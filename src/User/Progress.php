<?php


namespace Feniks\Bot\User;


use Discord\Discord;
use Discord\Parts\Guild\Guild;
use Discord\Parts\User\Member;

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

    public function reAssignRoles($points)
    {
        $level = $this->level($points);
        $ranks = $this->ranks();

        $this->removeOldRoles($this->currentRole($points));
        $this->addCurrentRole($this->currentRole($points));
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


    private function removeOldRoles($ignoreRole = null)
    {
        $ranks = $this->ranks();
        foreach ($ranks as $rankId => $rank) {
            if (isset($ranks[$rankId]['role']) && $ranks[$rankId]['role'] != 0 && $ranks[$rankId]['role'] != $ignoreRole) {
                if($this->canAssignRole((int) $ranks[$rankId]['role'])) {
                    $this->memberGuild->members->fetch($this->member->id)
                        ->then(function (Member $member) use($ranks, $rankId)  {
                            $member->removeRole((int) $ranks[$rankId]['role'])
                                ->then(function() use($ranks, $rankId, $member) {
                                    $this->guild->audit("Removed <@&{$ranks[$rankId]['role']}> from <@{$member->id}>.", $this->discord);
                                }, function (\Exception $e) use($ranks, $rankId, $member) {
                                    //$this->guild->audit("Just making sure <@{$member->id}> does not have <@&{$ranks[$rankId]['role']}>: `{$e->getMessage()}`", $this->discord);
                                });
                        }, function (\Exception $e){
                            $this->guild->audit("Error fetching user data: `{$e->getMessage()}`", $this->discord, 'warning');
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
            $this->guild->audit("Unable to add role <@&{$role}> to <@{$this->member->id}>, check permissions", $this->discord);
        }
        $this->member->addRole($role)
            ->then(function() use($role) {
                $this->guild->audit("Gave <@&{$role}> to <@{$this->member->id}>", $this->discord);
            },
            function(\Exception $e) use($role) {
                $this->guild->audit("Error when giving <@&{$role}> to <@{$this->member->id}>: `{$e->getMessage()}`", $this->discord);
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