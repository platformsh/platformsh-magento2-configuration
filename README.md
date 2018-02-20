# Magento 2 configuration for Platform.sh

Provides configuration to deploy a Magento 2 project on Platform.sh

## Project Variables

This project relies on the following project variables being set prior to initial deploy.

- ADMIN_USERNAME (defaults to “admin”)
- ADMIN_FIRSTNAME (defaults to “John”)
- ADMIN_LASTNAME (defaults to “Doe”)
- ADMIN_EMAIL (defaults to “john@example.com”)
- ADMIN_PASSWORD (defaults to “admin12”)
- ADMIN_URL (defaults to “admin”)
- APPLICATION_MODE (defaults to “production”)_

The latter can be changed at any time adjust the Application Mode on the next deploy.

## Get via Composer

This package is available in [Packagist][1].

You can get it via Composer by adding the following line to your `composer.json`:

```
"require": {
  "platformsh/magento2-configuration": "2.2.*"
},
```

[1]:	https://packagist.org/packages/platformsh/magento2-configuration