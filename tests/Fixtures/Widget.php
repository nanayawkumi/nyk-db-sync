<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Widget extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}
