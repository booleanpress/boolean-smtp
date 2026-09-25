<?php

/**
 * REST controller exposing transport metadata (settings schema, validation rules, presets).
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Http\Controllers;

use BooleanSmtp\Core\Http\Controller;
use BooleanSmtp\Core\Http\JsonResponse;
use BooleanSmtp\Core\Http\Request;
use BooleanSmtp\Services\Mailer\MailerManager;

/**
 * Describes the mail transports available to the connection form, without exposing credentials.
 *
 * @since 1.0.0
 */
class TransportController extends Controller {
    /**
     * @since 1.0.0
     *
     * @param MailerManager $mailer Resolves the registered transport classes.
     */
    public function __construct(
        protected MailerManager $mailer
    ) {}

    /**
     * Handle `GET /booleansmtp/v1/transports`.
     *
     * Lists every registered transport with its display name, delivery modes, SMTP presets,
     * settings schema, and validation rules. Unregistered transport classes are excluded from
     * the response.
     *
     * @since 1.0.0
     *
     * @param Request $request Unused; every registered transport's metadata is returned.
     * @return JsonResponse Transports keyed by driver name.
     */
    public function index(Request $request): JsonResponse {
        $transports = $this->mailer->getTransports();
        $result     = [];

        foreach ($transports as $driver => $class) {
            if (!class_exists($class)) {
                continue;
            }

            $instance = new $class();
            $schema   = $instance->getSettingsSchema();
            $rules    = $instance->getValidationRules();

            $this->augmentKeyStoreSchemaAndRules($schema, $rules, $driver);

            /**
             * Filters a transport's connection settings schema before the admin UI renders it.
             *
             * Applies to every transport after the plugin's own fields are in place. Add a field
             * here to collect an extra setting on the connection form — the add-on adds the
             * webhook secret of the providers that report delivery events this way. A field
             * follows the schema shape (`type`, `label`, `required`, `default`, optional `help`,
             * `options`, `visible_when`); its value is stored with the connection and, when its
             * name matches a secret fragment or `boolean_smtp_sensitive_keys`, encrypted.
             *
             * @since 1.0.0
             *
             * @param  array<string, array<string, mixed>> $schema The settings schema, keyed by field name.
             * @param  string                              $driver Transport driver slug.
             * @return array<string, array<string, mixed>> The filtered schema.
             */
            $schema = \apply_filters('boolean_smtp_transport_settings_schema', $schema, $driver);

            $result[$driver] = [
                'driver'           => $driver,
                'name'             => $instance->getName(),
                'delivery_modes'   => $instance->getDeliveryModes(),
                'smtp_presets'     => $instance->getSmtpPresets(),
                'settings_schema'  => $schema,
                'validation_rules' => $rules
            ];
        }

        return $this->ok($result);
    }

    /**
     * Handle `GET /booleansmtp/v1/transports/{driver}`.
     *
     * Reads the `driver` route parameter and returns that single transport's metadata, in the
     * same shape as one entry of {@see index()}.
     *
     * @since 1.0.0
     *
     * @param Request $request Request carrying the `driver` route parameter.
     * @return JsonResponse The transport's metadata, or a 404 error when the driver is unknown.
     */
    public function show(Request $request): JsonResponse {
        $driver     = $request->param('driver');
        $transports = $this->mailer->getTransports();

        if (!isset($transports[$driver]) || !class_exists($transports[$driver])) {
            return $this->error("Transport '{$driver}' not found.", 404);
        }

        $class    = $transports[$driver];
        $instance = new $class();
        $schema   = $instance->getSettingsSchema();
        $rules    = $instance->getValidationRules();

        $this->augmentKeyStoreSchemaAndRules($schema, $rules, $driver);
        /** This filter is documented above. */
        $schema = \apply_filters('boolean_smtp_transport_settings_schema', $schema, $driver);

        return $this->ok([
            'driver'           => $driver,
            'name'             => $instance->getName(),
            'delivery_modes'   => $instance->getDeliveryModes(),
            'smtp_presets'     => $instance->getSmtpPresets(),
            'settings_schema'  => $schema,
            'validation_rules' => $rules
        ]);
    }

    /**
     * Add the `key_store` (credential source) field to a transport's schema and rules when the
     * transport did not already declare one.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed>  $schema  Transport settings schema, modified in place.
     * @param array<string, string> $rules   Transport validation rules, modified in place.
     * @param string|null           $driver  Driver key, reserved for future per-driver overrides.
     */
    private function augmentKeyStoreSchemaAndRules(array &$schema, array &$rules, ?string $driver = null): void {
        if (!isset($schema['key_store'])) {
            $schema['key_store'] = [
                'type'     => 'select',
                'label'    => 'Credential Source',
                'required' => false,
                'default'  => 'db',
                'options'  => [
                    'db'        => 'Database (encrypted)',
                    'wp_config' => 'wp-config.php constant',
                    'env'       => 'Environment variable'
                ]
            ];
        }

        if (!isset($rules['key_store'])) {
            $rules['key_store'] = 'nullable|in:db,wp_config,env';
        }
    }
}
