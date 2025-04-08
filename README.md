# DomainFlow Core

[![Tests](https://github.com/domainflow/core/actions/workflows/tests.yml/badge.svg)](https://github.com/domainflow/core/actions/workflows/tests.yml)
![Packagist Version](https://img.shields.io/packagist/v/domainflow/core)
![PHP Version](https://img.shields.io/packagist/php-v/domainflow/core)
![License](https://img.shields.io/github/license/domainflow/core)
![PHPStan](https://img.shields.io/badge/PHPStan-Level%209-brightgreen.svg)

The **DomainFlow Core** package is a **Lightweight Application Bootstrapper** with features like **Service Providers**, **Application Bootstrapping**, **Middleware**, **Event Management**, and **Configuration Management** to help structure and maintain PHP back-end applications and microservices.

---

## ✨ Core Functionality

- **Application Container**  
  Inherits all DI capabilities from [DomainFlow Container](https://www.github.com/domainflow/container), including class auto-wiring, singleton bindings, and contextual bindings.

- **Service Providers**  
  Register and configure your services, including deferred loading for improved performance (load services only when first requested).

- **Bootstrapping & Lifecycle Management**  
  Built-in support for structured boot phases and graceful termination.

- **Event Management**  
  A basic but extendable event dispatcher to publish and subscribe to application events.

- **Configuration & Environment Management**  
  Manage environment variables, base paths, and config paths out of the box.

- **Caching**  
  Optionally cache resolved service instances (and deferred providers) for faster subsequent loads.

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

// 1. Define your own service provider.
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

// 2. Create a new application.
$app = new Application();

// 3. Register your provider.
$app->registerProvider(new MyServiceProvider());

// 4. Boot the application (register event listeners, run boot callbacks, etc.).
$app->boot();

// 5. Get your service.
$service = $app->get(MyService::class);
$service->doSomething();
```

---

More details and usage examples can be found in our [DomainFlow Core documentation](https://www.domainflow.dev/docs/core).

---

## 🛡 License

**DomainFlow Core** is open-sourced software licensed under the [MIT license](https://opensource.org/license/MIT).
