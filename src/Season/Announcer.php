<?php


namespace Feniks\Bot\Season;


use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\Parts\Embed\Embed;
use Feniks\Bot\Models\Season;
use Illuminate\Support\Carbon;
use function Psr\Log\warning;

class Announcer
{

  private $discord;

  public function __construct(Discord $discord)
  {
    $this->discord = $discord;
  }

  public function starting()
  {
    $seasons = Season::whereDate('start_date', '<=', Carbon::now('UTC'))->where('announced', false)->get();
    foreach($seasons as $season) {
      $channel = $this->discord->getChannel($season->guild->settings()->get('general.announcement-channel', null));

      if($channel) {
        $embed = new \Feniks\Bot\Embed($season->guild);
        $embed
          ->title($season->name)
          ->description($season->description)
          ->field(
            ':date: Season start:',
            $season->start_date,
            true
          )
          ->field(
            ':date: Season end:',
            $season->end_date,
            true
          );

        $reply = MessageBuilder::new()
          ->addEmbed(new Embed($this->discord, $embed->toArray()));


        if(! $channel->getBotPermissions()->view_channel || ! $channel->getBotPermissions()->send_messages ||  ! $channel->getBotPermissions()->embed_links) {
            $this->discord->getLogger()->warning('No access to announcement channel for guild '. $season->guild->discord_id);
            return;
        }
        $channel->sendMessage($reply)
            ->otherwise(function (Message $reply) use($season) {
                $this->discord->getLogger()->warning('Unable to announce season.');
            })
            ->done(function (Message $reply) use($season) {
          $season->announced = true;
          $season->save();
        });
      }
    }
  }

}