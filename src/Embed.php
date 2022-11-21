<?php


namespace Feniks\Bot;


use Feniks\Bot\Models\Guild;

class Embed
{
  var $embed = [];

  public function __construct($guild)
  {
    $this->embed = [
      'color' => '#FEE75C',
      "title" => "",
      "description" => "",
      'fields' => [

      ],
      'footer' => array(
        'icon_url'  => 'https://cdn.discordapp.com/avatars/1024290414443905044/4ffaa5507e58881550547c0a012cf59f.webp?size=1024',
        'text'  => 'Feniks',
      ),
      'timestamp' => now('UTC'),
    ];

    if($guild instanceof Guild) {
        $this->embed['author'] = [
            'name' => $guild->name,
            'icon_url' => $guild->avatar
        ];
    }

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

  public function thumbnail($thumbnail)
  {
    $this->embed['thumbnail']['url'] = $thumbnail;

    return $this;
  }

    public function image($image)
    {
        $this->embed['image']['url'] = $image;

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
