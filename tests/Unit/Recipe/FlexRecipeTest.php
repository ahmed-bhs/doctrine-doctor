<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Tests\Unit\Recipe;

use AhmedBhs\DoctrineDoctor\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Yaml;

/**
 * The Flex recipe (manifest.json + config/packages/) is copied into applications:
 * its config must only be loaded where the bundle is registered, and be valid.
 */
final class FlexRecipeTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    #[Test]
    public function the_bundle_is_registered_in_dev_and_test_only(): void
    {
        self::assertSame(['dev', 'test'], $this->bundleEnvironments());
    }

    #[Test]
    public function the_recipe_config_is_never_loaded_where_the_bundle_is_not_registered(): void
    {
        $config = $this->recipeConfig();

        self::assertArrayNotHasKey(
            'doctrine_doctor',
            $config,
            'A root "doctrine_doctor" key is loaded in every environment, including prod where the bundle is not registered: '
            . '"There is no extension able to load the configuration for doctrine_doctor".',
        );

        foreach (array_keys($config) as $key) {
            self::assertMatchesRegularExpression('/^when@(\w+)$/', (string) $key);
            self::assertContains(substr((string) $key, 5), $this->bundleEnvironments(), $key);
        }
    }

    #[Test]
    public function the_recipe_config_is_valid_in_every_environment(): void
    {
        foreach ($this->recipeConfig() as $environment => $config) {
            self::assertIsArray($config);
            self::assertArrayHasKey('doctrine_doctor', $config, $environment);

            $processed = new Processor()->processConfiguration(new Configuration(), [$config['doctrine_doctor']]);

            self::assertArrayHasKey('enabled', $processed, $environment);
        }
    }

    /**
     * @return list<string>
     */
    private function bundleEnvironments(): array
    {
        /** @var array{bundles: array<string, list<string>>} $manifest */
        $manifest = json_decode((string) file_get_contents(self::ROOT . '/manifest.json'), true, flags: \JSON_THROW_ON_ERROR);

        return $manifest['bundles']['AhmedBhs\DoctrineDoctor\DoctrineDoctorBundle'];
    }

    /**
     * @return array<string, mixed>
     */
    private function recipeConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = Yaml::parseFile(self::ROOT . '/config/packages/doctrine_doctor.yaml', Yaml::PARSE_CUSTOM_TAGS);

        return $config;
    }
}
