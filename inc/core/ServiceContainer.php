<?php
namespace SaltAI\Core;

class ServiceContainer {
    protected $services = [];
    private $factories = [];

    public function set(string $key, $instance): void {
        $this->services[$key] = $instance;
    }

    public function set_lazy($key, callable $factory) {
        $this->factories[$key] = $factory;
    }

    public function get(string $key) {
        if (!$this->has($key)) {
            error_log("Service '{$key}' not found in container.");
            return null;
        }
        
        if (isset($this->factories[$key])) {
            $this->services[$key] = call_user_func($this->factories[$key]);
            unset($this->factories[$key]);
            return $this->services[$key];
        }

        // Lazy load (closure ise çalıştır)
        if (is_callable($this->services[$key])) {
            $this->services[$key] = call_user_func($this->services[$key]);
        }

        return $this->services[$key];
    }

    public function has(string $key): bool {
        return isset($this->services[$key]) || isset($this->factories[$key]);
    }

    public function remove(string $key): void {
        unset($this->services[$key]);
    }
}