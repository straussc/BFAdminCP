<?php namespace ADKGamers\Webadmin\Libs\CLI;

/*

Copyright (c) 2010, dealnews.com, Inc.
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

 * Redistributions of source code must retain the above copyright notice,
   this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright
   notice, this list of conditions and the following disclaimer in the
   documentation and/or other materials provided with the distribution.
 * Neither the name of dealnews.com, Inc. nor the names of its contributors
   may be used to endorse or promote products derived from this software
   without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
POSSIBILITY OF SUCH DAMAGE.

 */

use ADKGamers\Webadmin\Libs\Helpers\Main AS Helper;

class SimpleProgress extends Progress
{
    private $cols;
    private $limiter;
    private $units;
    private $total;

    public function __construct()
    {
        // change the fps limit as needed
        $this->limiter = new FPSLimit(10);
        echo "\n";
    }

    public function __destruct()
    {
        $this->draw();
    }

    public function updateSize()
    {
        // get the number of columns
        $this->cols = exec("tput cols");
    }

    public function draw()
    {
        $this->updateSize();

        Helper::showCLIStatus($this->units, $this->total, $this->cols, $this->cols);
    }

    public function update($units, $total)
    {
        $this->units = $units;

        $this->total = $total;

        if(!$this->limiter->frame())
            return;

        $this->draw();
    }
}
