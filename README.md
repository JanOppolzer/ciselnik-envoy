# Číselnik-Envoy

In this repository, you can find a deployment script for [Laravel](https://laravel.com) application called [Číselník](https://github.com/JanOppolzer/ciselnik-laravel). This script is written in [Laravel Envoy](https://laravel.com/docs/9.x/envoy).

## Requirements

Laravel Envoy is currently available only for _macOS_ and _Linux_ operating systems.

However, on Windows you could try using either [Windows Subsystem for Linux](https://docs.microsoft.com/en-us/windows/wsl/install-win10) or [Laravel Homestead](https://laravel.com/docs/9.x/homestead). Of course, you can also use a virtualized Linux system inside, for example, [VirtualBox](https://www.virtualbox.org).

The destination host should be running Ubuntu 22.04 LTS (Jammy Jellyfish) with PHP 8.1. If that is not the case, take care and tweak PHP-FPM service in `Envoy.blade.php` and in Apache configuration accordingly.

## Installation

You need to [install](https://laravel.com/docs/9.x/envoy#installation) Laravel Envoy using [composer](https://getcomposer.org) before being able to run any Envoy scripts.

```bash
composer global require laravel/envoy
```

## Setup

In order for this Envoy script to be really useful and do what it is designed for, you must setup Apache, PHP, MariaDB and Shibboleth SP at the destination host first.

### Apache

Install Apache using `apt`.

```bash
apt install apache2
```

Then get a TLS certificate. If you would like to avoid paying to a Certificate Authority, use [Certbot](https://certbot.eff.org) to get a certificate from [Let's Encrypt](https://letsencrypt.org) for free. Then configure Apache securely according to [Mozilla SSL Configuration Generator](https://ssl-config.mozilla.org/#server=apache) using the following stub.

```apache
<VirtualHost *:80>
    ServerName      server.example.org
    Redirect        permanent / https://server.example.org
</VirtualHost>

<VirtualHost _default_:443>
    ServerName      server.example.org
    DocumentRoot    /home/web/ciselnik/current/public/

    # TLS settings

    <Directory /home/web/ciselnik/current/public>
        AllowOverride All
    </Directory>

    <Location />
        AuthType shibboleth
        ShibRequestSetting requireSession 0
        <RequireAll>
            Require shibboleth
        </RequireAll>
    </Location>

    <Location /Shibboleth.sso>
        SetHandler shib
    </Location>
</VirtualHost>
```

It is also highly recommended to allow `web` user (the user defined in `config` file in the `TARGET_USER` variable, i.e. the one under which Číselník application is saved in `/home` directory) to reload and restart PHP-FPM. It helps with minimizing outage during deployment of a new version. Edit `/etc/sudoers.d/web` accordingly:

```
web ALL=(ALL) NOPASSWD:/bin/systemctl reload php8.1-fpm,/bin/systemctl restart php8.1-fpm
```

### PHP

PHP 8.1 is present as an official package in recommended Ubuntu 22.04 LTS (Jammy Jellyfish).

```bash
apt install php-fpm
```

Then follow information in your terminal.

```bash
a2enmod proxy_fcgi setenvif
a2enconf php8.1-fpm
systemctl restart apache2
```

(In case you still run on older Ubuntu version or Debian distribution with not so current PHP version, you might find [Ondřej Surý's repository](https://launchpad.net/~ondrej/+archive/ubuntu/php) highly useful.)

### Shibboleth SP

Install and configure Shibboleth SP.

```bash
apt install libapache2-mod-shib
```

There is a [documentation](https://www.eduid.cz/cs/tech/sp/shibboleth) (in Czech language, though) available at [eduID.cz](https://www.eduid.cz/cs/tech/sp/shibboleth) federation web page.

You should add _AttributeChecker_ (Číselník requires _uniqueId_, _mail_ and _cn_ attributes) and _AttributeExtractor_ (to obtain useful information from federation metadata).

```xml
<ApplicationDefaults entityID="https://server.example.org/shibboleth"
    REMOTE_USER="uniqueId"
    sessionHook="/Shibboleth.sso/AttrChecker"
    metadataAttributePrefix="Meta-"
    cipherSuites="DEFAULT:!EXP:!LOW:!aNULL:!eNULL:!DES:!IDEA:!SEED:!RC4:!3DES:!kRSA:!SSLv2:!SSLv3:!TLSv1:!TLSv1.1">

    <Sessions lifetime="28800" timeout="3600" relayState="ss:mem"
        checkAddress="false" handlerSSL="true" cookieProps="https"
        redirectLimit="exact">

        <!-- Attribute Checker -->
        <Handler type="AttributeChecker" Location="/AttrChecker" template="attrChecker.html"
            attributes="uniqueId mail cn" flushSession="true"/>

    </Sessions>

    <!-- Extract information from SAML metadata -->
    <AttributeExtractor type="Metadata" DisplayName="displayName"
        InformationURL="informationURL" OrganizationURL="organizationURL">
        <ContactPerson id="Technical-Contact" contactType="technical" formatter="$EmailAddress"/>
    </AttributeExtractor>

</ApplicationDefaults>
```

Then tweak `attrChecker.html`, `localLogout.html` and `metadataError.html` so users are informed properly when any issue occurs. If you need any help, check GÉANT's documentation regarding [attribute checking](https://wiki.geant.org/display/eduGAIN/How+to+configure+Shibboleth+SP+attribute+checker).

### Envoy

To use prepared `Envoy.blade.php`, you are required to go through three steps including cloning this repository.

First, clone this repository.

```bash
git clone https://github.com/JanOppolzer/ciselnik-envoy
```

Second, install dependencies using `composer`.

```bash
composer install
```

Third, you need to prepare a _configuration file_ for your deployment using `config.example` template.

```bash
cp config.example config
```

Tweaking `config` file to your needs should be easy as all the variables within the file are self explanatory. Then just run the _deploy_ task.

```bash
envoy run deploy
```

## Tasks

There are three different tasks available — `deploy`, `rollback` and `cleanup`.

### deploy

The `deploy` task simply deploys the current Číselník version available at GitHub into timestamped directory and makes a symbolic link `current`. This helps you with rolling back to the previous version.

```bash
envoy run deploy
```

### rollback

The `rollback` task is there to help you roll back to the previous version if there is an issue with the current one. It just finds the previous timestamped directory and changes `current` symbolic link to that directory.

```bash
envoy run rollback
```

In case you would like to go back even further, just `ssh` into your web server and create a symbolic link to any version you have still available there.

### cleanup

The `cleanup` task helps you keeping your destination directory clean by leaving only three latest versions (i.e. timestamped directories) available and deletes all the older versions.

```bash
envoy run cleanup
```

## Why no stories?

_Laravel Envoy_ allows to use "stories" to help with grouping a set of tasks. Eventually, it makes the whole script much more readable as well as reusable.

There is one downside with stories, though. If your SSH agent requests confirming every use of your key (a highly recommended best practice!), you must confirm the key usage for every single Envoy story. I find it **very** annoying so therefore I have decided not to use stories after all.
