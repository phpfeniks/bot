<?php

namespace Feniks\Bot\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Season extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function guild()
    {
        return $this->belongsTo(Guild::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['points']);
    }


}
