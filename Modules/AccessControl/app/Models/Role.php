<?php

namespace Modules\AccessControl\Models;

use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    public const ADMIN = 'admin';

    public const CONSULTANT = 'consultant';

    /**
     * Protected roles cannot be deleted; the admin role cannot be modified
     * at all, the consultant role cannot be renamed (section 7.2).
     */
    public function isProtected(): bool
    {
        return in_array($this->name, [self::ADMIN, self::CONSULTANT], true);
    }

    public function isAdmin(): bool
    {
        return $this->name === self::ADMIN;
    }
}
