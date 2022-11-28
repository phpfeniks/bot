<?php


namespace Feniks\Bot\User;


use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Guild\Guild;
use Discord\Parts\User\Member;
use Feniks\Bot\Models\AuditLog;

class Progress
{
    use Ranks;

    protected $discord;
    protected $guild;
    protected $member;
    protected $memberGuild;

    public function __construct(Discord $discord, Guild $guild, Member $member)
    {
        $this->discord = $discord;
        $this->memberGuild = $guild;
        $this->guild = \Feniks\Bot\Models\Guild::where('discord_id', $guild->id)->first();
        $this->member = $member;

    }

    public function levelUp($guild, $user, $points)
    {
        $ranks = $this->guild->settings()->get('points.ranks', []);

        $currentScore = $guild->pivot->points;
        $newScore = $guild->pivot->points + $points;
        if($points === null) {
            $newScore = 0;
        }
        $currentRank = -1;
        $newRank = null;
        $nextRank = null;

        foreach($ranks as $rankId => $rank) {
            //dump([$rankId, $currentScore, (int) $rank['requirement']]);
            if($currentScore >= (int) $rank['requirement']) {
                $currentRank = $rankId;
            }
            if($newScore >= (int) $rank['requirement']) {
                $newRank = $rankId;
            }
        }

        if($newRank !== null && $newRank > $currentRank) {
            if (isset($ranks[$newRank + 1])) {
                $nextRank = $newRank + 1;
            }

            $channel = $this->discord->getChannel($this->guild->settings()->get('general.announcement-channel', null));

            if ($channel) {
                $annRank = $newRank+1;
                $levelUpMessage = "You have reached level {$annRank} :tada:".PHP_EOL;

                if($guild->settings()->get('points.stack-level-messages', false) ) {
                    for($rankMsg=$currentRank+1; $rankMsg < $newRank; $rankMsg++) {
                        if(isset($ranks[$rankMsg]['message']) && trim($ranks[$rankMsg]['message']) !== '') {
                            $levelUpMessage .= PHP_EOL.$ranks[$rankMsg]['message'].PHP_EOL;
                        }
                    }
                }


                if(isset($ranks[$newRank]['message']) && trim($ranks[$newRank]['message']) !== '') {
                    $levelUpMessage .= PHP_EOL.$ranks[$newRank]['message'];
                }

                $embed = [
                    'color' => '#FEE75C',
                    'author' => [
                        'name' => $guild->name,
                        'icon_url' => $guild->avatar
                    ],
                    "title" => ":sparkles: DING! :sparkles:",
                    "description" => "<@{$user->discord_id}>! {$levelUpMessage}",
                    'fields' => array(
                        '0' => array(
                            'name' => ':arrow_forward: Current score',
                            'value' => "`{$newScore} XP`",
                            'inline' => true
                        ),
                        '1' => array(
                            'name' => ":arrow_up:  Next level",
                            'value' => $nextRank ? "`{$ranks[$nextRank]['requirement']} XP`" : '-',
                            'inline' => true
                        ),
                    ),
                    'footer' => array(
                        'icon_url' => 'https://cdn.discordapp.com/avatars/1022932382237605978/5f28c64903f5a1e6919cae962c5ebe80.webp?size=1024',
                        'text' => 'Powered by Feniks',
                    ),
                ];
                $embed = new Embed($this->discord, $embed);
                $reply = MessageBuilder::new()
                    ->setContent("<@{$user->discord_id}>")
                    ->addEmbed($embed);

                if ($channel->getBotPermissions()->view_channel && $channel->getBotPermissions()->send_messages && $channel->getBotPermissions()->embed_links) {
                    $channel->sendMessage($reply)
                        ->otherwise(function () use ($guild) {
                            $this->discord->getLogger()->warning('Unable to announce level up ', ['guild_id' => $guild->discord_id]);
                        })
                        ->done(function (Message $reply) {

                        }
                        );
                } else {
                    $guild->audit("Bot is missing permissions to <#{$channel->id}>", $this->discord, 'warning');
                }

            }

            if ($channel->getBotPermissions()->manage_roles === true || $channel->getBotPermissions()->administrator) {
                $this->reAssignRoles($newScore);
            } else {
                $guild->audit('Bot is missing permissions to manage roles.', $this->discord, 'warning');
            }
        }
        $guild->pivot->points = $newScore;
        $guild->pivot->save();
    }

    public function reAssignRoles($points)
    {
        $this->removeOldRoles($points);
        $this->addRoles($points);
    }

    private function addRoles($points)
    {
        if(! $this->guild->settings()->get('points.stack-roles', false)) {
            $this->addCurrentRole($this->currentRole($points));
            return;
        }

        $ranks = $this->ranks();
        foreach ($ranks as $rankId => $rank) {
            if($ranks[$rankId]['requirement'] <= $points) {
                $this->addCurrentRole($ranks[$rankId]['role']);
            }
        }
    }
    public function currentRole($points)
    {
        $ranks = $this->ranks();
        $level = $this->level($points);

        $role = 0;
        foreach($ranks as $rankId => $rank) {
            if($level >= $rankId && isset($rank['role']) && $rank['role'] != 0) {
                $role = $rank['role'];
            }
        }

        return (int) $role;
    }


    private function removeOldRoles($points)
    {
        $ignoreRole = $this->currentRole($points);
        $ranks = $this->ranks();

        foreach ($ranks as $rankId => $rank) {
            if($this->guild->settings()->get('points.stack-roles', false) && $ranks[$rankId]['requirement'] < $points) {
                continue;
            }
            if (isset($ranks[$rankId]['role']) && $ranks[$rankId]['role'] != 0 && $ranks[$rankId]['role'] != $ignoreRole) {
                if($this->canAssignRole((int) $ranks[$rankId]['role'])) {
                    $this->memberGuild->members->fetch($this->member->id)
                        ->then(function (Member $member) use($ranks, $rankId)  {
                            $member->removeRole((int) $ranks[$rankId]['role'])
                                ->then(function() use($ranks, $rankId, $member) {
                                    $this->guild->audit("Making sure <@&{$ranks[$rankId]['role']}> is removed from <@{$member->id}>.", $this->discord);
                                }, function (\Exception $e) use($ranks, $rankId, $member) {
                                    //$this->guild->audit("Just making sure <@{$member->id}> does not have <@&{$ranks[$rankId]['role']}>: `{$e->getMessage()}`", $this->discord);
                                });
                        }, function (\Exception $e){
                            $this->guild->audit("Error fetching user data: `{$e->getMessage()}`", $this->discord, AuditLog::WARNING);
                        });
                }
            }
        }
    }

    private function addCurrentRole($role)
    {
        if($role == 0) {
            return;
        }
        if(! $this->canAssignRole($role)) {
            $this->guild->audit("Unable to add role <@&{$role}> to <@{$this->member->id}>, check permissions", $this->discord, AuditLog::ERROR);
        }
        $this->memberGuild->members->fetch($this->member->id, true)
            ->then(function (Member $member) use($role)  {
                $member->addRole($role)
                    ->done(
                        function() use($role, $member) {
                            $this->guild->audit("Gave <@&{$role}> to <@{$member->id}>", $this->discord);
                        },
                        function(\Exception $e) use($role, $member) {
                            $this->guild->audit("Error when giving <@&{$role}> to <@{$member->id}>: `{$e->getMessage()}`", $this->discord, AuditLog::WARNING);
                        }
                    );
            }, function (\Exception $e) use($role) {
                $this->guild->audit("Error when giving <@&{$role}> to <@{$this->member->id}>. Error fetching user data: `{$e->getMessage()}`", $this->discord, AuditLog::WARNING);
            });
    }

    private function canAssignRole($roleId)
    {
        if($roleId == 0) {
            return false;
        }
        if ($roleId == $this->memberGuild->id) {
            return false;
        }

        if (! $bot = $this->memberGuild->members->get('id', $this->discord->id)) {
            return false;
        }

        if (! $role = $this->memberGuild->roles->get('id', $roleId)) {
            return false;
        }

        foreach($bot->roles as $botRole)
        {
            if ($botRole->position > $role->position && $botRole->permissions->manage_roles)
                return true;
        }

        return false;
    }
}