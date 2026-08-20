# DomainFlow Core

[![Tests](https://github.com/domainflow/core/actions/workflows/tests.yml/badge.svg)](https://github.com/domainflow/core/actions/workflows/tests.yml)
![Packagist Version](https://img.shields.io/packagist/v/domainflow/core)
![PHP Version](https://img.shields.io/packagist/php-v/domainflow/core)
![License](https://img.shields.io/github/license/domainflow/core)
![PHPStan](https://img.shields.io/badge/PHPStan-Level%2010-brightgreen.svg)

The **DomainFlow Core** package is a **Lightweight Application Bootstrapper** with features like **Service Providers**, **Application Bootstrapping**, **Middleware**, **Event Management**, and **Configuration Management** to help structure and maintain PHP back-end applications and microservices.

---

## ⚙️ Requirements

- **PHP 8.4 or 8.5**

---

## ✨ Core Functionality

- **Application Container**  
  Inherits all DI capabilities from [DomainFlow Container](https://www.github.com/domainflow/container), including class auto-wiring, singleton bindings, and contextual bindings.

- **Service Providers**  
  Register and configure your services, including deferred loading for improved performance (load services only when first requested). Providers may opt into `OrderedServiceProviderInterface` to guarantee that declared dependencies register and boot before their dependents.

- **Bootstrapping & Lifecycle Management**  
  Built-in support for structured boot phases and graceful termination.

- **Event Management**  
  A basic but extendable event dispatcher to publish and subscribe to application events.

- **Configuration & Environment Management**  
  Manage environment variables, base paths, and config paths out of the box.

- **Caching**  
  Optionally persist validated, versioned class-string bindings across processes via `FileContainerCache`, a filesystem-backed adapter for [DomainFlow Container](https://www.github.com/domainflow/container)'s declarative definitions cache (`$app->setExternalCache(new FileContainerCache($path))`). Tracked definition files use exact mtime plus SHA-256 fingerprints, and a stable lock serializes concurrent cache mutations. A warm cache hit never skips the boot lifecycle and never re-hydrates a resolved object — only class-string bindings and aliases are restored, and every service is still resolved fresh through the normal container path.

---

## 📦 Installation

Install **DomainFlow Core** with Composer:

```sh
composer require domainflow/core
```

---

## 🧪 Example Usage

Below is a minimal example demonstrating how to set up an application, register a service provider, and retrieve a service:

```php
<?php

use DomainFlow\Application;
use DomainFlow\Service\AbstractServiceProvider;

// 1. Define the service your provider will register.
class MyService
{
    public function doSomething(): string
    {
        return 'done';
    }
}

// 2. Define your own service provider. AbstractServiceProvider only requires
//    register(); boot(), provides(), and isDeferred() already have usable
//    defaults (isDeferred() reflects $defer below).
class MyServiceProvider extends AbstractServiceProvider
{
    protected array $providedServices = [MyService::class];
    public bool $defer = true; // Lazy loading, only load on first use

    public function register(Application $app): void
    {
        // Bind a service...
        $app->bind(MyService::class, fn() => new MyService(), true);
    }
}

// 3. Create a new application.
$app = new Application();

// 4. Register your provider.
$app->registerProvider(new MyServiceProvider());

// 5. Boot the application (register event listeners, run boot callbacks, etc.).
$app->boot();

// 6. Get your service.
$service = $app->get(MyService::class);
$service->doSomething();
```

---

More details and usage examples can be found in our [DomainFlow Core documentation](https://www.domainflow.dev/docs/core).

---

## 🛡 License

**DomainFlow Core** is open-sourced software licensed under the [MIT license](https://opensource.org/license/MIT).
