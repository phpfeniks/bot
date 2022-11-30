<?php


namespace Feniks\Bot\Commands;


use Discord\Builders\MessageBuilder;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\User\Member;
use Feniks\Bot\Models\AuditLog;
use Feniks\Bot\Models\Guild as GuildModel;
use Feniks\Bot\User\Progress;

class Balance extends Command
{

    protected $name = 'verify';
    protected $description = '(managers only) Verify roles according to XP. Should only be run after modifying server levels';
    protected $sigV = 3;

    public function handle(Interaction $interaction)
    {
        $guild = GuildModel::where('discord_id', $interaction->guild_id)->first();

        $managerRoles = $guild->settings()->get('general.manager-roles', []);
        $hasRole = false;
        foreach($managerRoles as $managerRole) {
            if($interaction->member->roles->has($managerRole)) {
                $hasRole = true;
            }
        }

        if(! $hasRole) {
            $embed = new \Feniks\Bot\Embed($interaction->guild);
            $embed
                ->title(':information_source: No access')
                ->description(":robot: Bip boop! You are not allowed to use this command.")
                ->field(
                    ':ticket: Server owner?',
                    "Log in to the dashboard at [feniksbot.com](https://feniksbot.com) to configure a manager role. Then add users to that role."
                );

            $interaction->respondWithMessage(MessageBuilder::new()->addEmbed(new Embed($this->discord, $embed->toArray())), true);
            return;
        }

        $guild->audit("Level re-balance initiated by <@{$interaction->user->id}>.", $this->discord, AuditLog::WARNING);
        $embed = new \Feniks\Bot\Embed($guild);
        $embed
            ->title(':information_source: Starting server roles re-balance.')
            ->description(":robot: This may take a while. Please be patient.");
        $interaction->respondWithMessage(MessageBuilder::new()->addEmbed(new Embed($this->discord, $embed->toArray())), true);
        foreach($guild->users()->get() as $user) {
            $interaction->guild->members->fetch($user->discord_id, true)
                ->then(function (Member $member) use($interaction, $guild, $user) {
                    $progress = new Progress($this->discord, $interaction->guild, $member);
                    $progress->reAssignRoles($user->pivot->points);
                });
        }
    }
}