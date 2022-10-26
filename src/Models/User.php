<?php

namespace Feniks\Bot\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;


class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'avatar',
        'discord_id',
        'discord_token',
        'discord_refresh_token',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'remember_token',
    ];


    public function guilds()
    {
        return $this->belongsToMany(Guild::class)
            ->withPivot(['points', 'username']);
    }

    public function seasons()
    {
        return $this->belongsToMany(Season::class)
            ->withPivot(['points']);
    }

}
