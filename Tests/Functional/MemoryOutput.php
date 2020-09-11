<?php

namespace JMS\JobQueueBundle\Tests\Functional;

use Symfony\Component\Console\Output\Output;

class MemoryOutput extends Output
{
  private $output;

  protected function doWrite($message, $newline)
  {
    $this->output .= $message;

    if ($newline) {
      $this->output .= "\n";
    }
  }

  public function getOutput()
  {
    return $this->output;
  }
}
