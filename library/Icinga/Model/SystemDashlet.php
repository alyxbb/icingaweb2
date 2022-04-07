<?php

/* Icinga Web 2 | (c) 2022 Icinga GmbH | GPLv2+ */

namespace Icinga\Model;

use Icinga\Web\Dashboard;
use ipl\Orm\Model;
use ipl\Orm\Relations;

class SystemDashlet extends Model
{
    public function getTableName()
    {
        return 'icingaweb_system_dashlet';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'dashlet_id',
            'module_dashlet_id',
            'username'
        ];
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo(Dashboard\Dashlet::TABLE, Dashlet::class);
        $relations->belongsTo('icingaweb_module_dashlet', ModuleDashlet::class)
            ->setCandidateKey('module_dashlet_id');
    }
}
