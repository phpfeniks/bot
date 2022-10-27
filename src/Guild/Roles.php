<?php


namespace Feniks\Bot\Guild;


use Feniks\Bot\Models\Role;

class Roles
{
  public function sync($guild, $guildId)
  {
    foreach($guild->roles as $role) {
      Role::updateOrCreate([
        'discord_id' => $role->id,
      ], [
        'guild_id' => $guildId,
        'name' => $role->name,
      ]);
    }
  }
}