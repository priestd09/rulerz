<?php

namespace RulerZ\Compiler;

use RulerZ\Executor\Executor;
use RulerZ\Parser\Parser;

class Compiler
{
    /**
     * @var Parser
     */
    private $parser;

    /**
     * @var Evaluator
     */
    private $evaluator;

    public static function create($cacheDirectory = null)
    {
        return new static(new FileEvaluator($cacheDirectory));
    }

    public function __construct(Evaluator $evaluator)
    {
        $this->parser = new Parser();
        $this->evaluator = $evaluator;
    }

    /**
     * @return Executor
     */
    public function compile($rule, CompilationTarget $target, Context $context)
    {
        $context['rule_identifier'] = $this->getRuleIdentifier($target, $rule);
        $context['executor_classname'] = 'Executor_' . $context['rule_identifier'];
        $context['executor_fqcn'] = '\RulerZ\Compiled\Executor\\Executor_' . $context['rule_identifier'];

        if (!class_exists($context['executor_fqcn'], false)) {
            $compiler = function() use ($rule, $target, $context) {
                return $this->compileToSource($rule, $target, $context);
            };

            $this->evaluator->evaluate($context['rule_identifier'], $compiler);
        }

        return new $context['executor_fqcn']();
    }

    protected function compileToSource($rule, CompilationTarget $compilationTarget, Context $context)
    {
        $ast = $this->parser->parse($rule);
        $executorModel = $compilationTarget->compile($ast, $context);

        $flattenedTraits = implode(PHP_EOL, array_map(function($trait) {
            return "\t" . 'use ' . $trait . ';';
        }, $executorModel->getTraits()));

        $extraCode = '';
        foreach ($executorModel->getCompiledData() as $key => $value) {
            $extraCode .= sprintf('private $%s = %s;' . PHP_EOL, $key, var_export($value, true));
        }

        return <<<EXECUTOR
namespace RulerZ\Compiled\Executor;

use RulerZ\Executor\Executor;

class {$context['executor_classname']} implements Executor
{
    $flattenedTraits

    $extraCode

    // $rule
    protected function execute(\$target, array \$operators, array \$parameters)
    {
        return {$executorModel->getCompiledRule()};
    }
}
EXECUTOR;
    }

    protected function getRuleIdentifier(CompilationTarget $compilationTarget, $rule)
    {
        return hash('crc32b', get_class($compilationTarget) . $rule);
    }
}
