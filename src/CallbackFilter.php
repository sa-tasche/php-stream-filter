<?php

namespace Clue\StreamFilter;

use php_user_filter;
use InvalidArgumentException;
use ReflectionFunction;
use Exception;

/**
 *
 * @internal
 * @see append()
 * @see prepend()
 */
class CallbackFilter extends php_user_filter
{
    private $callback;
    private $closed = true;
    private $supportsClose = false;

    public function onCreate()
    {
        $this->closed = false;

        if (!is_callable($this->params)) {
            throw new InvalidArgumentException('No valid callback parameter given to stream_filter_(append|prepend)');
        }
        $this->callback = $this->params;

        // callback supports end event if it accepts invocation without arguments
        $ref = new ReflectionFunction($this->callback);
        $this->supportsClose = ($ref->getNumberOfRequiredParameters() === 0);

        return true;
    }

    public function onClose()
    {
        // callback supports closing and is not already closed
        if ($this->supportsClose) {
            $this->supportsClose = false;
            // invoke without argument to signal end and discard resulting buffer
            try {
                call_user_func($this->callback);
            } catch (Exception $ignored) {
                // ignored
            }
        }

        $this->closed = true;
        $this->callback = null;
    }

    public function filter($in, $out, &$consumed, $closing)
    {
        // concatenate whole buffer from input brigade
        $data = '';
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            $data .= $bucket->data;
        }

        // skip processing callback that already ended
        if ($this->closed) {
            return PSFS_FEED_ME;
        }

        // only invoke filter function if buffer is not empty
        // this may skip flushing a closing filter
        if ($data !== '') {
            try {
                $data = call_user_func($this->callback, $data);
            } catch (Exception $e) {
                // exception should mark filter as closed
                $this->onClose();
                throw $e;
            }
        }

        // mark filter as closed after processing closing chunk
        if ($closing) {
            $this->closed = true;

            // callback supports closing and is not already closed
            if ($this->supportsClose) {
                $this->supportsClose = false;

                // invoke without argument to signal end and append resulting buffer
                $data .= call_user_func($this->callback);
            }
        }

        if ($data !== '') {
            // create a new bucket for writing the resulting buffer to the output brigade
            // reusing an existing bucket turned out to be bugged in some environments (ancient PHP versions and HHVM)
            $bucket = @stream_bucket_new($this->stream, $data);

            // legacy PHP versions (PHP < 5.4) do not support passing data from the event signal handler
            // because closing the stream invalidates the stream and its stream bucket brigade before
            // invoking the filter close handler.
            if ($bucket !== false) {
                stream_bucket_append($out, $bucket);
            }
        }

        return PSFS_PASS_ON;
    }
}
