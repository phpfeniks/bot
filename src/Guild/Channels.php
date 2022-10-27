<?php


namespace Feniks\Bot\Guild;


use Feniks\Bot\Models\Channel;

class Channels
{
  public function sync($guild, $guildId)
  {
    foreach($guild->channels as $channel) {
      if($channel->type == 0) {
        Channel::updateOrCreate([
          'discord_id' => $channel->id,
        ], [
          'guild_id' => $guildId,
          'name' => $channel->name,
          'type' => $channel->type,
        ]);
      }
    }

  }
}