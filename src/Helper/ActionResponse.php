<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Helper;

use Awengan\HyperfAdmin\Reply;

/**
 * @deprecated 用 {@see Reply} 替代（同一个类，保留旧名是为了不打断已有代码）。
 */
class_alias(Reply::class, __NAMESPACE__ . '\\ActionResponse');
