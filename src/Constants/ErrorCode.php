<?php

declare(strict_types=1);

namespace Awengan\HyperfAdmin\Constants;

use Hyperf\Constants\AbstractConstants;
use Hyperf\Constants\Annotation\Constants;
use Hyperf\Constants\Annotation\Message;

#[Constants]
class ErrorCode extends AbstractConstants
{
    #[Message('Error')]
    public const COMMON_ERROR = 40000;

    #[Message('Unauthenticated')]
    public const UNAUTHORIZED = 40100;

    #[Message('Forbidden')]
    public const FORBIDDEN = 40300;

    #[Message('Page not found')]
    public const NOT_FOUND = 40400;

    #[Message('Invalid parameters')]
    public const VALIDATION_ERROR = 42200;

    #[Message('Server error')]
    public const SERVER_ERROR = 50000;
}
