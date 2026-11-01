<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * 审计日志条目（只读展示用）。
 *
 * @property int $id
 * @property int $user_id
 * @property string $username
 * @property string $action
 * @property string $method
 * @property string $path
 * @property string $summary
 * @property string $ip
 * @property int $status
 * @property string $created_at
 */
class AuditLogEntry extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'admin_logs';

    protected array $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'status' => 'integer',
    ];
}
