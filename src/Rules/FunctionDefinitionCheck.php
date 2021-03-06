<?php declare(strict_types = 1);

namespace PHPStan\Rules;

use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PHPStan\Analyser\Scope;
use PHPStan\Broker\Broker;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Type\NonexistentParentClassType;

class FunctionDefinitionCheck
{

	const VALID_TYPEHINTS = [
		'self',
		'static',
		'array',
		'callable',
		'string',
		'int',
		'bool',
		'float',
		'void',
		'iterable',
	];

	/**
	 * @var \PHPStan\Broker\Broker
	 */
	private $broker;

	/**
	 * @var \PHPStan\Rules\ClassCaseSensitivityCheck
	 */
	private $classCaseSensitivityCheck;

	public function __construct(
		Broker $broker,
		ClassCaseSensitivityCheck $classCaseSensitivityCheck
	)
	{
		$this->broker = $broker;
		$this->classCaseSensitivityCheck = $classCaseSensitivityCheck;
	}

	/**
	 * @param \PhpParser\Node\FunctionLike $function
	 * @param \PHPStan\Analyser\Scope $scope
	 * @param string $parameterMessage
	 * @param string $returnMessage
	 * @return string[]
	 */
	public function checkFunction(
		FunctionLike $function,
		Scope $scope,
		string $parameterMessage,
		string $returnMessage
	): array
	{
		if ($function instanceof ClassMethod) {
			return $this->checkParametersAcceptor(
				$scope->getClassReflection()->getMethod($function->name, $scope),
				$parameterMessage,
				$returnMessage
			);
		}
		if ($function instanceof Function_) {
			$functionName = $function->name;
			if (isset($function->namespacedName)) {
				$functionName = (string) $function->namespacedName;
			}
			$functionNameName = new Name($functionName);
			if (!$this->broker->hasFunction($functionNameName)) {
				return [];
			}
			return $this->checkParametersAcceptor(
				$this->broker->getFunction($functionNameName),
				$parameterMessage,
				$returnMessage
			);
		}

		$errors = [];
		foreach ($function->getParams() as $param) {
			$class = $param->type instanceof NullableType
				? (string) $param->type->type
				: (string) $param->type;
			if ($class === '' || in_array($class, self::VALID_TYPEHINTS, true)) {
				continue;
			}

			if (!$this->broker->hasClass($class)) {
				$errors[] = sprintf($parameterMessage, $param->name, $class);
			} else {
				$errors = array_merge(
					$errors,
					$this->classCaseSensitivityCheck->checkClassNames([$class])
				);
			}
		}

		$returnType = $function->getReturnType() instanceof NullableType
			? (string) $function->getReturnType()->type
			: (string) $function->getReturnType();

		if (
			$returnType !== ''
			&& !in_array($returnType, self::VALID_TYPEHINTS, true)
		) {
			if (!$this->broker->hasClass($returnType)) {
				$errors[] = sprintf($returnMessage, $returnType);
			} else {
				$errors = array_merge(
					$errors,
					$this->classCaseSensitivityCheck->checkClassNames([$returnType])
				);
			}
		}

		return $errors;
	}

	private function checkParametersAcceptor(
		ParametersAcceptor $parametersAcceptor,
		string $parameterMessage,
		string $returnMessage
	): array
	{
		$errors = [];
		foreach ($parametersAcceptor->getParameters() as $parameter) {
			$referencedClasses = $parameter->getType()->getReferencedClasses();
			foreach ($referencedClasses as $class) {
				if (!$this->broker->hasClass($class)) {
					$errors[] = sprintf($parameterMessage, $parameter->getName(), $class);
				}
			}
			$errors = array_merge(
				$errors,
				$this->classCaseSensitivityCheck->checkClassNames($referencedClasses)
			);
			if ($parameter->getType() instanceof NonexistentParentClassType) {
				$errors[] = sprintf($parameterMessage, $parameter->getName(), $parameter->getType()->describe());
			}
		}

		$returnTypeReferencedClasses = $parametersAcceptor->getReturnType()->getReferencedClasses();
		foreach ($returnTypeReferencedClasses as $class) {
			if (!$this->broker->hasClass($class)) {
				$errors[] = sprintf($returnMessage, $class);
			}
		}
		$errors = array_merge(
			$errors,
			$this->classCaseSensitivityCheck->checkClassNames($returnTypeReferencedClasses)
		);
		if ($parametersAcceptor->getReturnType() instanceof NonexistentParentClassType) {
			$errors[] = sprintf($returnMessage, $parametersAcceptor->getReturnType()->describe());
		}

		return $errors;
	}

}
