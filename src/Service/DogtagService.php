<?php

namespace App\Service;

use App\Entity\Clan;
use App\Entity\User;
use App\Entity\UserGamer;
use App\Exception\GamerLifecycleException;
use App\Helper\EmailRecipient;
use App\Idm\IdmManager;
use App\Idm\IdmRepository;
use App\Repository\UserGamerRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class DogtagService
{
    private readonly LoggerInterface $logger;
    private readonly UserGamerRepository $repo;
    private readonly IdmRepository $userRepo;
    final public const DATETIME_FORMAT = 'Y.m.d H:i:s';

    /*
     * Clarification: User is the Symfony User with information from IDM, while Gamer is the local KLMS information,
     * i.e. the status w.r.t this KLMS instance.
     */

    public function __construct(LoggerInterface $logger,
                                UserGamerRepository $repo,
                                IdmManager $manager)
    {
        $this->logger = $logger;
        $this->repo = $repo;
        $this->userRepo = $manager->getRepository(User::class);
    }

    public function getUsersByIds(array $ids): array
    {
      return array_map(fn (User $u) => ['id' => $u->getUuid()->toString(), 'nickname' => $u->getNickname()], $this->userRepo->findById($ids));
    }

    public function getUsers($withUnPaid = false): array
    {
        $gamers = $this->repo->findAll();
        $gamers = array_filter($gamers, fn (UserGamer $g) => $g->hasRegistered() && ($withUnPaid || $g->hasPaid()));
        $ids = array_map(fn (UserGamer $g) => $g->getUuid()->toString(), $gamers);
        return $this->getUsersByIds($ids);
    }

    public function generateDogTagMatrix($options, $ids = []): void
    {
        define('SHOW_HELPERS', false);
        $users = !empty($ids) ? $this->getUsersByIds($ids) : $this->getUsers(true);
        $userNames = array_map(fn($arr) => $arr['nickname'], $users);

        // Set dimensions for the image 5x3cm at 300 DPI
        $plateWidth = 591;
        $plateHeight = 354;
        $dpi = 300;
        $font_size_base = 50;
        $spacing = 10;
        $columns = 3;
        $maxRows = 4;

        $totalNames = count($userNames);
        $imageWidth = $plateWidth * $columns + $spacing * ($columns + 1);
        $imageHeight = $plateHeight * $maxRows + $spacing * ($maxRows + 1);

        $line1 = $options['lan']; // "1. KaiserLAN"
        $line3 = $options['date']; // "25.10.-27.10.2024";
      
        // Set base font size
        $font_file = __DIR__ . '/../../assets/ellenluff-jeko-variable.ttf';

        // Create image with GD
        $image = imagecreatetruecolor($imageWidth, $imageHeight);

        // Set background color (white)
        $background_color = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $background_color);

        // Set text color (black)
        $text_color = imagecolorallocate($image, 0, 0, 0);
        $white_color = imagecolorallocate($image, 255,255,255);
        $black_color = imagecolorallocate($image, 0, 0, 0);
        $red_color = imagecolorallocate($image, 255, 0, 0);
        $blue_color = imagecolorallocate($image, 0, 0, 255);
        $grey_transparent = imagecolorallocatealpha($image, 0, 0, 0, 100);

        if (SHOW_HELPERS) {
          // Draw the plates
          $currentRow = 0;
          $currentColumn = 0;
          for ($i = 0; $i < $columns * $maxRows; $i++) {
            $startPosX = $spacing + ($spacing + $plateWidth) * $currentColumn;
            $startPosY = $spacing + ($spacing + $plateHeight) * $currentRow;
            imagerectangle($image, $startPosX, $startPosY, $startPosX + $plateWidth, $startPosY + $plateHeight, $black_color);
            $currentColumn++;
            if ($currentColumn >= $columns) {
              $currentColumn = 0;
              $currentRow++;
            }
          }
        }


        $currentRow = 0;
        $currentColumn = 0;
        foreach ($userNames as $userName) {
          // single row:  spacing, (platewidth, spacing) * columns
          // single column: spacing, (plateheight, spacing) * rows
          $startPosX = $spacing + ($spacing + $plateWidth) * $currentColumn;
          $startPosY = $spacing + ($spacing + $plateHeight) * $currentRow;

          // debug stuff
          SHOW_HELPERS && imagefilledrectangle($image, $startPosX - 1, $startPosY - 1, $startPosX + 2, $startPosY + 2, $red_color);
          SHOW_HELPERS && imagettftext($image, 15, 0, $startPosX + 10, $startPosY + 18, $white_color, $font_file, 'name length: ' . strlen($userName) . '    x: ' . $startPosX . '   y: ' . $startPosY);

          if ($currentRow >= $maxRows) {
            if (SHOW_HELPERS) {
              $lastColumn = $currentColumn - 1;
              for ($i = 0; $i <= $columns; $i++) {
                // add additional dots to the bottom
                $startPosX = ($spacing + $plateWidth) * ($lastColumn + 1);
                $startPosY = ($spacing + $plateHeight) * ($currentRow + 1);
                imagefilledrectangle($image, $startPosX - 1, $startPosY - 1, $startPosX + 2, $startPosY + 2, $blue_color);
              }
            }
            break;
          }

          $line2 = $userName; // Can change length

          // Calculate dynamic font size and top offset for the second line
          $char_count = strlen($line2);
          $offset_top_line2_base = -2;
          switch (true) {
            case $char_count <= 10:
              $font_size_line2 = $font_size_base;
              $offset_top_line2 = $offset_top_line2_base + 8;
              break;
            case $char_count <= 13:
              $font_size_line2 = $font_size_base - 1;
              $offset_top_line2 = $offset_top_line2_base + 10;
              break;
            case $char_count <= 16:
              $font_size_line2 = $font_size_base - 5;
              $offset_top_line2 = $offset_top_line2_base + 11;
              break;
            case $char_count <= 19:
              $font_size_line2 = $font_size_base - 12;
              $offset_top_line2 = $offset_top_line2_base + 14;
              break;
            default:
              $font_size_line2 = $font_size_base - 15;
              $offset_top_line2 = $offset_top_line2_base + 15;
          }

          // Set positions for the text (in landscape orientation)
          $padding = 30; // Padding from the top and bottom
          // $line_height = 50; // Line height for text placement
          $lineThickness = 10;

          // Calculate text positioning for 3 lines
          $x1 = $startPosX + $padding; // Manual extra padding for centering
          $y1 = $startPosY + $padding + 65;

          $x2 = $startPosX + $padding;
          $y2 = $startPosY + $padding + 180 - $offset_top_line2;

          $font_size_line3 = 45;
          $x3 = $startPosX + $padding; // Manual extra padding for centering
          $y3 = $startPosY + $padding + 280;

          // Add text to the image
          imagettftext($image, $font_size_line3, 0, $x1, $y1, $text_color, $font_file, $line1);
          imagettftext($image, $font_size_line2, 0, $x2, $y2, $text_color, $font_file, $line2);
          imagettftext($image, $font_size_line3, 0, $x3, $y3, $text_color, $font_file, $line3);
          // imagettftext($image, 10, 0, 20, 15, $text_color, $font_file, 'line2 count: ' . $char_count);


          // Draw the first thick line (block) - as a filled rectangle
          $lineLeft = $startPosX + $plateWidth - $padding - $spacing;

          $firstLineTop = $startPosY + $plateHeight / 3;
          imagefilledrectangle($image, $startPosX + $padding, $firstLineTop, $lineLeft, $firstLineTop + $lineThickness, $black_color);

          // Draw the second thick line (block) - as another filled rectangle
          $secondLineTop = $startPosY + $plateHeight / 3 * 2 - $lineThickness / 2;
          imagefilledrectangle($image, $startPosX + $padding, $secondLineTop, $lineLeft, $secondLineTop + $lineThickness, $black_color);

          $currentColumn++;
          if ($currentColumn >= $columns) {
            $currentColumn = 0;
            $currentRow++;
          }
        }

        // Set image resolution to 300 DPI
        imageresolution($image, $dpi, $dpi);

        // Output the image
        header('Content-Type: image/png');
        imagepng($image);

        // Destroy the image to free memory
        imagedestroy($image);
    }
}
