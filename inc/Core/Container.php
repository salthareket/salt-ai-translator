<?php
namespace SAT\Core;

class Container {
    private array $bindings = [];

    public function set(string $key, mixed $value): void {
        $this->bindings[$key] = $value;
    }

    public function get(string $key): mixed {
        return $this->bindings[$key] ?? null;
    }

    public function has(string $key): bool {
        return isset($this->bindings[$key]);
    }
}
