<?php

namespace Feniks\Bot\Models;

use Discord\Discord;
use Discord\Parts\Channel\Message;
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

    public function audit($message, Discord $discord, $level = 'info')
    {
        $auditChannel = $discord->getChannel($this->settings()->get('general.audit-channel', null));


        if (!$auditChannel || !$auditChannel->getBotPermissions()->view_channel || !$auditChannel->getBotPermissions()->send_messages || !$auditChannel->getBotPermissions()->embed_links) {
            $discord->getLogger()->info('No access to audit channel for guild ' . $this->discord_id);
            return false;
        }

        $auditChannel->sendMessage("`{$level}` {$message}")->done();
    }

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

    public function isConfigured()
    {
        if($this->settings()->get('general.audit-channel', null) === null) {
            return false;
        }

        return true;
    }

}
