<?php

namespace Modules\AccessControl\Tests\Unit;

use Modules\AccessControl\Support\PermissionRegistry;
use Tests\TestCase;

class PermissionRegistryTest extends TestCase
{
    public function test_names_are_unique(): void
    {
        $names = PermissionRegistry::names();

        $this->assertSame($names, array_unique($names));
    }

    public function test_every_name_is_kebab_case_action_resource(): void
    {
        foreach (PermissionRegistry::names() as $name) {
            $this->assertMatchesRegularExpression('/^[a-z]+(-[a-z]+)+$/', $name, "Permission [{$name}] is not kebab-case.");
        }
    }

    public function test_the_default_consultant_permissions_exist_in_the_registry(): void
    {
        foreach (PermissionRegistry::consultantDefaults() as $name) {
            $this->assertContains($name, PermissionRegistry::names());
        }
    }

    public function test_the_registry_has_34_permissions(): void
    {
        $this->assertCount(34, PermissionRegistry::names());
    }
}
