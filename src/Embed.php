<?php


namespace Feniks\Bot;


class Embed
{
  var $embed = [];

  public function __construct($guild)
  {
    $this->embed = [
      'color' => '#FEE75C',
      'author' => [
        'name' => $guild->name,
        'icon_url' => $guild->avatar
      ],
      "title" => "",
      "description" => "",
      'fields' => [

      ],
      'footer' => array(
        'icon_url'  => 'https://cdn.discordapp.com/avatars/1022932382237605978/5f28c64903f5a1e6919cae962c5ebe80.webp?size=1024',
        'text'  => 'Powered by Feniks',
      ),
    ];

    return $this;
  }

  public function title($title)
  {
    $this->embed['title'] = $title;

    return $this;
  }

  public function description($description)
  {
    $this->embed['description'] = $description;

    return $this;
  }

  public function field($name, $value, $inline = false)
  {
    $this->embed['fields'][] = [
      'name' => $name,
      'value' => $value,
      'inline' => $inline,
    ];

    return $this;
  }

  public function toArray()
  {
    return $this->embed;
  }
}
