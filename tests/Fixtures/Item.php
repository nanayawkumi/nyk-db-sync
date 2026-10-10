<?php

declare(strict_types=1);

namespace Nanayawkumi\NykDbSync\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Item extends Model
{
    protected $table = 'items';

    protected $guarded = [];

    public $timestamps = false;

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'item_tag');
    }
}
