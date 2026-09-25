<?php
/**
 * Request to assess or import another SMTP plugin's connections and log.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Http\Requests;

use BooleanSmtp\Core\Http\FormRequest;

/**
 * Validates `POST /booleansmtp/v1/tools/migration/import`: which source, whether to write,
 * which halves (connections, one chunk of the log), the log cap, whether to restart the log
 * import from the beginning, and — for a dry run — the retention the log window should follow
 * instead of the stored setting (the Review step lets the user change it before applying).
 *
 * @since 1.0.0
 */
final class MigrationImportRequest extends FormRequest {
    /**
     * Validation rules.
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    public function rules(): array {
        return [
            'source'             => 'required|string|max:50',
            'dry_run'            => 'nullable|boolean',
            'import_connections' => 'nullable|boolean',
            'import_logs'        => 'nullable|boolean',
            'max_logs'           => 'nullable|integer|min:0|max:20000',
            'restart_logs'       => 'nullable|boolean',
            'retention_days'     => 'nullable|integer|min:0|max:3650',
            'resolutions'        => 'nullable|array',
        ];
    }
}
