<?php

namespace Feniks\Bot\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use xqus\ModelSettings\HasSettings;

class Guild extends Model
{
    use HasFactory;
    use HasSettings;

    protected $guarded = [];

    protected $casts = [
        'settings' => 'json',
    ];



    public function channels()
    {
        return $this->hasMany(Channel::class);
    }

    public function roles()
    {
        return $this->hasMany(Role::class);
    }

    public function seasons()
    {
        return $this->hasMany(Season::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['points', 'username']);
    }

    public function admins()
    {
        return $this->belongsToMany(User::class, 'user_administer');
    }

}
