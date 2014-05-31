<?php
/**
 * Template.php - logecho
 * 
 * @author joyqi
 */

namespace LE;

/**
 * Class Template
 * @package LE
 */
class Template
{
    /**
     * @var string
     */
    private $_templateDir;

    /**
     * @var string
     */
    private $_targetDir;

    /**
     * @var string
     */
    private $_str = '';

    /**
     * @var array
     */
    private $_temp = [];

    /**
     * @var array
     */
    private $_codes = [];

    /**
     * @param $templateDir
     * @param $targetDir
     * @throws \Exception
     */
    public function __construct($templateDir, $targetDir)
    {
        $this->_templateDir = $templateDir;
        $this->_targetDir = $targetDir;

        if (!is_dir($templateDir)) {
            throw new \Exception('Template directory is not exists: ' . $templateDir);
        }

        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                throw new \Exception('Target directory is not exists: ' . $targetDir);
            }
        }
    }

    /**
     * @param $file
     * @return string
     * @throws \Exception
     */
    private function getTemplate($file)
    {
        $file = $this->_templateDir . '/' . $file;
        if (!file_exists($file)) {
            throw new \Exception('Template file is not exists: ' . $file);
        }

        return file_get_contents($file);
    }

    /**
     * @param $var
     * @return string
     */
    private function getVar($var)
    {
        $parts = explode('.', $var);
        $result = '$';

        foreach ($parts as $key => $part) {
            if (0 == $key) {
                $result .= $part;
            } else {
                $result .= "['{$part}']";
            }
        }

        return $result;
    }

    /**
     * @param $var
     * @param $filter
     * @param mixed $arg
     * @return bool|string
     */
    private function callFilter($var, $filter, $arg)
    {
        switch ($filter) {
            case 'date':
                return date($arg ? 'Y-m-d' : $arg, $var);
            default:
                break;
        }
    }

    /**
     * @param $var
     * @param $filter
     * @param $arg
     * @return string
     */
    private function applyFilter($var, $filter, $arg)
    {
        if (empty($filter)) {
            return $var;
        }

        return '$' . "this->callFilter({$var}, '{$filter}', '{$arg}')";
    }

    /**
     * prepare all code blocks
     */
    private function parseCode()
    {
        preg_replace_callback("/<!--\s*PHP\s*-->(.+?)<!--\s*ENDPHP\s*-->/is", function ($matches) {
            $key = '===' . uniqid() . '===';
            $this->_codes[$key] = '<?php ' . $matches[1] . ' ?>';
            return $key;
        }, $this->_str);
    }

    /**
     * parse all include block
     */
    private function parseInclude()
    {
        $regex = "/<!--\s*INCLUDE\s+([_a-z0-9-\.]+)\s*-->/is";
        while (preg_match($regex, $this->_str)) {
            $this->_str = preg_replace_callback($regex, function ($matches) {
                return $this->getTemplate($matches[1]);
            }, $this->_str);
        }
    }

    /**
     * parse all loop block
     */
    private function parseLoop()
    {
        $this->_str = preg_replace_callback("/<!--\s*LOOP\s+([_a-z0-9\.]+):([_a-z0-9]+@)?([_a-z0-9]+)\s*-->/is",
            function ($matches) {
                $varSource = $this->getVar($matches[1]);
                $varIndex = empty($matches[2]) ? '$_' : $this->getVar(rtrim($matches[2]. '@'));
                $varEach = $this->getVar($matches[3]);
                return "<?php if (!empty({$varSource}) && is_array({$varSource})):"
                . "foreach ({$varSource} as {$varIndex} => {$varEach}): ?>";
            }, $this->_str);

        $this->_str = preg_replace("/<!--\s*ENDLOOP\s*-->/i", '<?php endforeach; endif; ?>', $this->_str);
    }

    /**
     * parse all case conditions
     */
    private function parseCase()
    {
        $this->_str = preg_replace_callback("/<!--\s*CASE\s+([_a-z0-9\.]+)\s*-->/is",
            function ($matches) {
                $var = $this->getVar($matches[1]);
                return "<?php if (!empty({$var})): ?>";
            }, $this->_str);

        $this->_str = preg_replace("/<!--\s*ENDCASE\s*-->/i", '<?php endif; ?>', $this->_str);
    }

    /**
     * parse all variables
     */
    private function parseVar()
    {
        $this->_str = preg_replace_callback("/\{\{([_a-z0-9\.]+)(?:@([_a-z0-9]+)(?::([^\}]+))?)?\}\}/i", function ($matches) {
            $var = $this->getVar($matches[1]);
            $result = $this->applyFilter($var,
                isset($matches[2]) ? $matches[2] : NULL,
                isset($matches[3]) ? $matches[3] : NULL);
            return "<?php if (isset({$var})): echo {$result}; endif; ?>";
        }, $this->_str);
    }

    /**
     * @param $template
     * @param $target
     * @param array $data
     * @throws \Exception
     */
    public function compile($template, $target, array $data)
    {
        $temp = sys_get_temp_dir() . '/' . md5($template . '&' . $target) . '.compile';

        if (!file_exists($temp)) {
            $this->_str = $this->getTemplate($template);
            $this->parseInclude();
            $this->parseCode();
            $this->parseCase();
            $this->parseLoop();
            $this->parseVar();

            str_replace(array_keys($this->_codes), array_values($this->_codes), $this->_str);

            file_put_contents($temp, $this->_str);
            $this->_temp[] = $temp;
        }

        ob_start();
        extract($data);
        require $temp;
        $str = ob_get_clean();

        $target = $this->_targetDir . '/' . $target;
        $dir = dirname($target);

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, false)) {
                throw new \Exception('Target directory is not exists: ' . $dir);
            }
        }

        info("> build {$template}=>{$target}");
        file_put_contents($target, $str);
    }

    /**
     * clear temp files
     */
    public function __destruct()
    {
        foreach ($this->_temp as $temp) {
            unlink($temp);
        }
    }
}