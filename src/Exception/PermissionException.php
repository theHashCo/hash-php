<?php

declare(strict_types=1);

namespace Hash\Exception;

/** 403: `insufficient_scope` (see getRequiredScope()), `tenant_suspended` or `company_not_ready`. */
class PermissionException extends HashException
{
    /** On `insufficient_scope`: the scope the operation needs. */
    public function getRequiredScope(): ?string
    {
        $v = $this->bodyField('required');

        return is_string($v) ? $v : null;
    }
}
