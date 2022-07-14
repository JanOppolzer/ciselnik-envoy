@include('vendor/autoload.php')

@setup
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__, 'config');

    try {
        $dotenv->load();
        $dotenv->required([
            'TARGET_SERVER', 'TARGET_USER', 'TARGET_DIR',
            'REPOSITORY',
            'APP_NAME', 'APP_ENV', 'APP_DEBUG', 'APP_URL',
            'LDAP_HOST', 'LDAP_USERNAME', 'LDAP_PASSWORD', 'LDAP_BASE_DN',
            'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        ])->notEmpty();
    } catch(Exception $e) {
        echo "Something went wrong:\n\n";
        echo "{$e->getMessage()} \n\n";
        exit;
    }

    $server = $_ENV['TARGET_SERVER'];
    $user = $_ENV['TARGET_USER'];
    $dir = $_ENV['TARGET_DIR'];

    $repository = $_ENV['REPOSITORY'];
    $branch = $branch ?? 'main';

    $app_name = $_ENV['APP_NAME'];
    $app_env = $_ENV['APP_ENV'];
    $app_debug = $_ENV['APP_DEBUG'];
    $app_url = $_ENV['APP_URL'];

    $db_host = $_ENV['DB_HOST'];
    $db_database = $_ENV['DB_DATABASE'];
    $db_username = $_ENV['DB_USERNAME'];
    $db_password = $_ENV['DB_PASSWORD'];

    $ldap_host = $_ENV['LDAP_HOST'];
    $ldap_username = $_ENV['LDAP_USERNAME'];
    $ldap_password = $_ENV['LDAP_PASSWORD'];
    $ldap_port = $_ENV['LDAP_PORT'] ?? '636';
    $ldap_base_dn = $_ENV['LDAP_BASE_DN'];
    $ldap_ssl = $_ENV['LDAP_SSL'] ?? 'true';
    $ldap_tls = $_ENV['LDAP_TLS'] ?? 'false';

    $slack_hook = $_ENV['LOG_SLACK_WEBHOOK_URL'] ?? null;
    $slack_channel = $_ENV['LOG_SLACK_CHANNEL'] ?? null;

    $destination = (new DateTime)->format('YmdHis');
    $symlink = 'current';
@endsetup

@servers(['web' => "$user@$server"])

@task('deploy', ['confirm' => true])
    echo "=> Install {{ $app_name }} into ~/{{ $dir }}/ at {{ $user }}"@"{{ $server }}..."

    echo "Check ~/{{ $dir }}/"
        if [ ! -d {{ $dir }} ]; then
            mkdir -p {{ $dir }}
        fi

    cd {{ $dir }}

    echo "Clone '{{ $branch }}' branch of {{ $repository }} into ~/{{ $dir }}/{{ $destination }}/"
        git clone {{ $repository }} --branch={{ $branch }} --depth=1 -q ~/{{ $dir }}/{{ $destination }}

    echo "Prepare ~/{{ $dir }}/.env"
        if [ ! -f .env ]; then
            cp {{ $destination }}/.env.example .env
        fi

    echo "Update ~/{{ $dir }}/.env"
        cp .env .env-{{ $destination }}.bak
        sed -i "s%APP_NAME=.*%APP_NAME={{ $app_name }}%; \
        s%APP_ENV=.*%APP_ENV={{ $app_env }}%; \
        s%APP_DEBUG=.*%APP_DEBUG={{ $app_debug }}%; \
        s%APP_URL=.*%APP_URL={{ $app_url }}%; \
        s%DB_HOST=.*%DB_HOST={{ $db_host }}%; \
        s%DB_DATABASE=.*%DB_DATABASE={{ $db_database }}%; \
        s%DB_USERNAME=.*%DB_USERNAME={{ $db_username }}%; \
        s%DB_PASSWORD=.*%DB_PASSWORD={{ $db_password }}%; \
        s%LDAP_HOST=.*%LDAP_HOST={{ $ldap_host }}%; \
        s%LDAP_USERNAME=.*%LDAP_USERNAME=\"{{ $ldap_username }}\"%; \
        s%LDAP_PASSWORD=.*%LDAP_PASSWORD=\"{{ $ldap_password }}\"%; \
        s%LDAP_PORT=.*%LDAP_PORT={{ $ldap_port }}%; \
        s%LDAP_BASE_DN=.*%LDAP_BASE_DN=\"{{ $ldap_base_dn }}\"%; \
        s%LDAP_SSL=.*%LDAP_SSL={{ $ldap_ssl }}%; \
        s%LDAP_TLS=.*%LDAP_TLS={{ $ldap_tls }}%; \
        s%LOG_SLACK_WEBHOOK_URL=.*%LOG_SLACK_WEBHOOK_URL={{ $slack_hook }}%" .env

    echo "Symlink ~/{{ $dir }}/.env"
        ln -s ../.env ~/{{ $dir }}/{{ $destination }}/.env

    echo "Check ~/{{ $dir }}/storage/ and fix permissions if necessary"
        if [ ! -d storage ]; then
            mv {{ $destination }}/storage .
            setfacl -Rm g:www-data:rwx,d:g:www-data:rwx storage
        else
            rm -rf {{ $destination }}/storage
        fi

    echo "Fix permissions to ~/{{ $dir }}/bootstrap/cache"
        setfacl -Rm g:www-data:rwx,d:g:www-data:rwx ~/{{ $dir }}/{{ $destination }}/bootstrap/cache

    echo "Symlink ~/{{ $dir }}/storage/"
        ln -s ../storage {{ $destination }}/storage

    echo "Unlink ~/{{ $dir }}{{ $symlink }}"
        if [ -h {{ $symlink }} ]; then
            rm {{ $symlink }}
        fi

    echo "Symlink ~/{{ $dir }}/{{ $destination }} to ~/{{ $dir }}/{{ $symlink }}"
        ln -s {{ $destination }} {{ $symlink }}

    echo "Install composer dependencies"
        cd current
        composer install -q --no-dev --optimize-autoloader --no-ansi --no-interaction --no-progress --prefer-dist
        cd ..

    echo "Generate key"
        if [ `grep '^APP_KEY=' .env | grep 'base64:' | wc -l` -eq 0 ]; then
            cd current
            php artisan key:generate -q --no-ansi --no-interaction
            cd ..
        fi

    cd {{ $destination }}

    echo "Migrate database tables"
        php artisan migrate --force -q --no-ansi --no-interaction

    echo "Optimize"
        php artisan optimize:clear -q --no-ansi --no-interaction

    echo "Cache config"
        php artisan config:cache -q --no-ansi --no-interaction

    echo "Cache routes"
        php artisan route:cache -q --no-ansi --no-interaction

    echo "Cache views"
        php artisan view:cache -q --no-ansi --no-interaction

    echo "Reload PHP-FPM"
        sudo systemctl reload php8.1-fpm
@endtask

@finished
    @slack($slack_hook, $slack_channel, "$app_name deployed to $server.")
@endfinished

