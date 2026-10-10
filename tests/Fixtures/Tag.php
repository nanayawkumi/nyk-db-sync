<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $table = 'tags';

    protected $guarded = [];

    public $timestamps = false;
}
