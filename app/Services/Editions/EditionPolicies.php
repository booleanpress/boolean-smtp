<?php
/**
 * Binds the plugin's own edition policies.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Editions;

use BooleanSmtp\Contracts\Editions\HealthAlertContract;
use BooleanSmtp\Contracts\Editions\MessageBodyStorageContract;
use BooleanSmtp\Contracts\Editions\NotificationLimitContract;
use BooleanSmtp\Contracts\Editions\SenderRouterContract;
use BooleanSmtp\Contracts\Editions\SenderRuleContract;
use BooleanSmtp\Core\Container\Container;

/**
 * The rules that differ between editions — who may share a sender, how shared senders route, the
 * notification-channel limit, the health alert and message-body storage — bound to the plugin's
 * own implementations. Bound, not shared, so each use resolves the current binding and an add-on
 * that binds its own policy later is always the one used.
 *
 * @since 1.0.0
 */
final class EditionPolicies
{
    /**
     * Contract => the plugin's implementation.
     *
     * @since 1.0.0
     * @var array<class-string, class-string>
     */
    public const DEFAULTS = [
        SenderRuleContract::class         => OneConnectionPerSender::class,
        SenderRouterContract::class       => FirstByPriority::class,
        NotificationLimitContract::class  => OneChannelPerType::class,
        HealthAlertContract::class        => SingleFailureAlert::class,
        MessageBodyStorageContract::class => AlwaysStoreMessageBodies::class,
    ];

    /**
     * Bind every contract to the plugin's implementation.
     *
     * @since 1.0.0
     *
     * @param  Container $container The plugin's container.
     * @return void
     */
    public static function bind(Container $container): void
    {
        foreach (self::DEFAULTS as $contract => $implementation) {
            $container->bind($contract, $implementation);
        }
    }
}
