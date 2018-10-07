<?php
/**
 * This file is part of the PracticalAfas package.
 *
 * (c) Roderik Muit <rm@wyz.biz>
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

use PHPUnit\Framework\TestCase;
use PracticalAfas\UpdateConnector\UpdateObject;
use PracticalAfas\UpdateConnector\OrgPersonContact;

class UpdateObjectTest extends TestCase
{
    /**
     * Runs through example payloads; verifies UpdateObject output matches them.
     */
    public function testOutput()
    {
        foreach (scandir(__DIR__ . '/update_examples') as $dir_entry) {
            if (substr($dir_entry, -4) === '.txt') {
                $this->processUpdateExample(__DIR__ . "/update_examples/$dir_entry");
            }
        }
    }

    /**
     * Runs through example payloads; verifies UpdateObject output matches them.
     *
     * @expectedExcep tion InvalidArgumentException
     * @expectedExcep tionMessage 'Hoi hoop'
     */
    public function testValidateDutchPhoneNr()
    {
        /** @var \PracticalAfas\UpdateConnector\UpdateObject $object */
        list($object) = $this->readUpdateExample(__DIR__ . '/update_examples/KnOrg-embedded-insert.txt');
        $object->getObject('KnContact')->setField('phone', '+3162251721', 0, OrgPersonContact::VALIDATE_FORMAT);
        //@todo continue here: this does not generate an error.
    }


    /**
     * Processes an example file; verifies UpdateObject output matches contents.
     *
     * @param string $filename
     *   The absolute filename.
     */
    private function processUpdateExample($filename) {
        /** @var \PracticalAfas\UpdateConnector\UpdateObject $object */
        list($object, $change_behavior, $expected_json, $expected_xml) = $this->readUpdateExample($filename);
        $test = $object->output('json', ['pretty' => true], $change_behavior);
        $this->assertSame($expected_json, $test, "JSON output does not match the contents of $filename.");
        $test = $object->output('xml', ['pretty' => true], $change_behavior);
        $this->assertSame($expected_xml, $test, "XML output does not match the contents of $filename.");
    }

    /**
     * Reads an example file; returns separate parts of the contents.
     *
     * @param string $filename
     *   The absolute filename.
     *
     * @return array
     *   $object, $change_behavior, $expected_json, $expected_xml
     */
    private function readUpdateExample($filename) {
        // We've dumped classname, input and output together into one file for
        // easier comparison by humans (so they can see if the test examples
        // make sense). Pull these apart.
        $object_type = $action = $buffer = '';
        $input_array = $expected_json = $eval = null;
        $change_behavior = UpdateObject::DEFAULT_CHANGE;
        $fp = fopen($filename, 'r');
        while ($line = fgets($fp)) {
            if ($line === false || trim($line) === '--') {
                // Buffer is supposedly complete; move it into whatever is the
                // next empty 'target'. There's not a lot of checking here; we
                // assume the input files are safe and don't cause e.g. the
                // eval() to break.
                if (!isset($input_array)) {
                    eval('$input_array = ' . $buffer . ';');
                    if (!isset($input_array)) {
                        throw new \UnexpectedValueException("Input array definition in $filename is apparently invalid.");
                    }
                } elseif (!isset($expected_json)) {
                    $expected_json = str_replace('{TODAY}', '"' . date('Y-m-d') . '"', trim($buffer));
                } elseif (!isset($expected_xml)) {
                    $expected_xml = str_replace('{TODAY}', date('Y-m-d'), trim($buffer));
                } else {
                    throw new \UnexpectedValueException("$filename contains extra content after output XML.");
                }
                $buffer = '';
            } elseif ($line[0] === ';') {
                // Skip comments. (They can be placed anywhere.)
                continue;
            } elseif (strpos($line, 'eval: ') === 0) {
                // Remember one 'eval'.
                $eval = substr($line, 5);
            } elseif ($object_type === '') {
                // First line.
                $object_type = trim($line);
                // Split off action & change behavior.
                if (($pos = strpos($object_type, ':')) !== false) {
                    $action = substr($object_type, $pos + 1);
                    $object_type = substr($object_type, 0, $pos);
                }
                if (($pos = strpos($action, ':')) !== false) {
                    $change_behavior = substr($action, $pos + 1);
                    $action = substr($action, 0, $pos);
                    if (!is_numeric($change_behavior) || $change_behavior < 0 || $change_behavior > 65535) {
                        throw new \UnexpectedValueException("'change behavior' on first line of $filename is invalid.");
                    }
                    $change_behavior = (int) $change_behavior;
                }
            } else {
                $buffer .= $line;
            }
        }
        // We need to partially redo the above code because $buffer is often
        // still full. Output XML is usually filled here, if the file does not
        // end with '--'.
        if ($buffer) {
            if (!isset($expected_json)) {
                throw new \UnexpectedValueException("$filename does not seem to contain both JSON and XML output.");
            }
            if (!isset($expected_xml)) {
                $expected_xml = str_replace('{TODAY}', date('Y-m-d'), trim($buffer));
            } else {
                throw new \UnexpectedValueException("$filename contains extra content after output XML.");
            }
        }
        if (!isset($expected_xml)) {
            throw new \UnexpectedValueException("$filename does not seem to contain output XML.");
        }

        $object = UpdateObject::create($object_type, $input_array, $action);
        if ($eval) {
            // We again assume the input files are safe.
            eval($eval);
        }

        return [$object, $change_behavior, $expected_json, $expected_xml];
    }
}
