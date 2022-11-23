<?php


namespace Feniks\Bot\Commands;


use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Builders\Components\TextInput;
use Discord\Builders\MessageBuilder;
use Discord\Helpers\Collection;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\User\Member;
use Feniks\Bot\Models\AuditLog;
use Feniks\Bot\Models\Guild as GuildModel;
use Feniks\Bot\Models\User;
use Feniks\Bot\User\Progress;
use function Symfony\Component\String\lower;

class ManageXp extends Command
{
    protected $name = 'xpmanager';
    protected $description = 'Manage XP for any given user';
    protected $sigV = 2;
    protected $options = [
        [
            'name' => 'user',
            'description' => 'User to manage XP for',
            'required' => true,
            'type' => Option::USER,
            'autocomplete' => false
        ]
    ];

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

        $user = User::where('discord_id', $interaction->data->options['user']->value)->first();
        if(! $user) {
            $user = User::updateOrCreate([
                'discord_id' => $interaction->data->options['user']->value,
            ], [
                'name' => '',
                'avatar' => '',
            ]);
            $guild->users()->syncWithoutDetaching([$user->id => []]);
            $guild->audit("User <@{$interaction->data->options['user']->value}> manually created with command.", $this->discord, AuditLog::INFO);
        }


        $interaction->guild->members->fetch($interaction->data->options['user']->value)
            ->then(function (Member $member) use($interaction, $guild) {
                $buttonRemove = Button::new(Button::STYLE_SECONDARY)
                    ->setLabel('Remove points');
                $buttonRemove->setListener(function (Interaction $interaction) use($member, $guild)  {

                    $ar = ActionRow::new();
                    $ti = TextInput::new('Points', TextInput::STYLE_SHORT, 'points');
                    $ar->addComponent($ti);

                    $customId = '123';
                    $interaction->showModal('Remove XP from user', $customId, [$ar], function (Interaction $interaction, Collection $components) use($member, $guild) {
                        $user = User::where('discord_id', $member->id)->first();
                        $userGuild = $user->guilds()->where('guilds.id', $guild->id)->first();

                        if(! $userGuild) {
                            $interaction->respondWithMessage(MessageBuilder::new()
                                ->setContent(":x: I have nothing on that user"), true);
                            return;
                        }
                        if(! is_numeric($components['points']->value)) {
                            $interaction->respondWithMessage(MessageBuilder::new()
                                ->setContent(":x: Not a number.."), true);
                            return;
                        }
                        $userGuild->pivot->points = $userGuild->pivot->points - $components['points']->value;
                        $userGuild->pivot->save();

                        $progress = new Progress($this->discord, $interaction->guild, $member);
                        $progress->reAssignRoles($userGuild->pivot->points);

                        $guild->audit("<@{$interaction->member->id}> removed `{$components['points']->value} XP` from <@{$member->id}>.", $this->discord);

                        $interaction->respondWithMessage(MessageBuilder::new()
                            ->setContent("Removed `{$components['points']->value} XP` from `{$member->displayname}`."), true);
                    });
                }, $this->discord);

                $buttonClear = Button::new(Button::STYLE_DANGER)
                    ->setLabel('Clear all points');
                $buttonClear->setListener(function (Interaction $interaction) use($member, $guild)  {

                    $ar = ActionRow::new();
                    $ti = TextInput::new('Type confirm to confirm', TextInput::STYLE_SHORT, 'confirm')->setPlaceholder('CONFIRM');
                    $ar->addComponent($ti);

                    $customId = '124';
                    $interaction->showModal('Remove all XP from user', $customId, [$ar], function (Interaction $interaction, Collection $components) use($member, $guild) {
                        $user = User::where('discord_id', $member->id)->first();
                        $userGuild = $user->guilds()->where('guilds.id', $guild->id)->first();

                        if(! $userGuild) {
                            $interaction->respondWithMessage(MessageBuilder::new()
                                ->setContent(":x: I have nothing on that user"), true);
                            return;
                        }
                        if(\strtolower($components['confirm']->value) !== 'confirm') {
                            $interaction->respondWithMessage(MessageBuilder::new()
                                ->setContent(":x: Please type `confirm`."), true);
                            return;
                        }
                        $userGuild->pivot->points = $userGuild->pivot->points = 0;
                        $userGuild->pivot->save();

                        $progress = new Progress($this->discord, $interaction->guild, $member);
                        $progress->reAssignRoles($userGuild->pivot->points);

                        $guild->audit("<@{$interaction->member->id}> removed `ALL XP` from <@{$member->id}>.", $this->discord);

                        $interaction->respondWithMessage(MessageBuilder::new()
                            ->setContent("Removed `ALL XP` from `{$member->displayname}`."), true);
                    });
                }, $this->discord);

                $buttonAdd = Button::new(Button::STYLE_SUCCESS)
                    ->setLabel('Add points');
                $buttonAdd->setListener(function (Interaction $interaction) use($member, $guild)  {

                    $ar = ActionRow::new();
                    $ti = TextInput::new('Points to add', TextInput::STYLE_SHORT, 'points');
                    $ar->addComponent($ti);

                    $customId = '125';
                    $interaction->showModal('Add points to user', $customId, [$ar], function (Interaction $interaction, Collection $components) use($member, $guild) {
                        $user = User::where('discord_id', $member->id)->first();
                        $userGuild = $user->guilds()->where('guilds.id', $guild->id)->first();

                        if(! $userGuild) {
                            $interaction->respondWithMessage(MessageBuilder::new()
                                ->setContent(":x: I have nothing on that user"), true);
                            return;
                        }
                        if(! is_numeric($components['points']->value)) {
                            $interaction->respondWithMessage(MessageBuilder::new()
                                ->setContent(":x: Not a number.."), true);
                            return;
                        }
                        $userGuild->pivot->points = $userGuild->pivot->points + $components['points']->value;
                        $userGuild->pivot->save();

                        $progress = new Progress($this->discord, $interaction->guild, $member);
                        $progress->reAssignRoles($userGuild->pivot->points);

                        $guild->audit("<@{$interaction->member->id}> added `{$components['points']->value} XP` to <@{$member->id}>.", $this->discord);

                        $interaction->respondWithMessage(MessageBuilder::new()
                            ->setContent("Added `{$components['points']->value} XP` to `{$member->displayname}`."), true);
                    });
                }, $this->discord);

                $row = ActionRow::new()
                    ->addComponent($buttonAdd)
                    ->addComponent($buttonRemove)
                    ->addComponent($buttonClear)
                ;

                $embed = new \Feniks\Bot\Embed($interaction->guild);
                $embed
                    ->title("XP manager")
                    ->thumbnail($member->avatar ? $member->avatar : $member->user->avatar)
                    ->description(":robot: Bip boop!  Managing <@{$member->id}>. Pick any option below.")
                    ;

                $interaction->respondWithMessage(MessageBuilder::new()
                    ->addEmbed(new Embed($this->discord, $embed->toArray()))
                    ->addComponent($row)
                , true);
            }, function() use($interaction) {
                $interaction->respondWithMessage(MessageBuilder::new()->setContent(':x: User not found. Whops.'), true);
            });



    }
}