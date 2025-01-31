<?php

namespace AmpProject\Tooling\Validator;

use AmpProject\Tooling\Validator\SpecGenerator\ConstantNames;
use AmpProject\Tooling\Validator\SpecGenerator\FileManager;
use AmpProject\Tooling\Validator\SpecGenerator\Section;
use AmpProject\Tooling\Validator\SpecGenerator\SpecPrinter;
use Exception;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpNamespace;

final class SpecGenerator
{
    use ConstantNames;

    /**
     * Namespace under which to find the spec generator files.
     *
     * @var string
     */
    const GENERATOR_NAMESPACE = 'AmpProject\\Tooling\\Validator\\SpecGenerator';

    /**
     * Generate the PHP spec from a JSON definition.
     *
     * @param array  $jsonSpec      Validator spec as defined by the downloaded JSON file.
     * @param array  $bundlesConfig Bundles configuration.
     * @param string $rootNamespace Root namespace to generate the PHP validator spec under.
     * @param string $destination   Destination folder to store the PHP validator spec under.
     */
    public function generate($jsonSpec, $bundlesConfig, $rootNamespace, $destination)
    {

        $extensionsMeta = $this->gatherExtensionsMeta($bundlesConfig);

        $printer = new SpecPrinter();
        $printer->setTypeResolving(false);

        $fileManager = new FileManager($rootNamespace, $destination, $printer);

        $fileManager->ensureDirectoriesExist();

        list($file, $namespace) = $fileManager->createNewNamespacedFile();

        /** @var ClassType $class */
        $class = $namespace->addClass('Spec')
                           ->setFinal();

        $namespace->addUse("{$rootNamespace}\\Spec");

        $jsonSpec     = $this->adaptJsonSpec($jsonSpec);
        $specRuleKeys = $this->collectSpecRuleKeys($jsonSpec);

        $this->generateEntityClass('AttributeList', $fileManager);
        $this->generateEntityClass('DeclarationList', $fileManager);
        $this->generateEntityClass('DescendantTagList', $fileManager);
        $this->generateEntityClass('CssRuleset', $fileManager);
        $this->generateEntityClass('DocRuleset', $fileManager);
        $this->generateEntityClass('Error', $fileManager);
        $this->generateEntityClass('Tag', $fileManager);
        $this->generateEntityClass('TagWithExtensionSpec', $fileManager);
        $this->generateEntityClass('AggregateTag', $fileManager);
        $this->generateEntityClass('AggregateTagWithExtensionSpec', $fileManager);
        $this->generateEntityClass('Identifiable', $fileManager, 'interface');
        $this->generateEntityClass('IterableSection', $fileManager, 'interface');
        $this->generateEntityClass('Iteration', $fileManager, 'trait');
        $this->generateErrorCodeInterface($jsonSpec, $fileManager);
        $this->generateSpecRuleInterface($specRuleKeys, $fileManager);

        foreach ($jsonSpec as $section => $sectionSpec) {
            switch ($section) {
                case 'minValidatorRevisionRequired':
                case 'specFileRevision':
                    $class->addProperty($section, $sectionSpec)
                          ->setPrivate()
                          ->addComment("@var int");

                    $class->addMethod($section)
                          ->addBody('return $this->?;', [$section])
                          ->addComment("@return int");
                    break;
                case 'scriptSpecUrl':
                case 'stylesSpecUrl':
                case 'templateSpecUrl':
                    $class->addProperty($section, $sectionSpec)
                          ->setPrivate()
                          ->addComment("@var string");

                    $class->addMethod($section)
                          ->addBody('return $this->?;', [$section])
                          ->addComment("@return string");
                    break;
                default:
                    $sectionClassName = $this->generateSectionClass(
                        $section,
                        $sectionSpec,
                        $fileManager,
                        $extensionsMeta
                    );

                    $class->addProperty($section)
                          ->setPrivate()
                          ->addComment("@var Spec\\Section\\{$sectionClassName}");

                    $class->addMethod($section)
                          ->addBody('if ($this->? === null) {', [$section])
                          ->addBody("    \$this->? = new Spec\\Section\\{$sectionClassName}();", [$section])
                          ->addBody('}')
                          ->addBody('return $this->?;', [$section])
                          ->addComment("@return Spec\\Section\\{$sectionClassName}");
            }
        }

        $fileManager->saveFile($file, 'Spec.php');
    }

    /**
     * Get the class name for a given value.
     *
     * @param string $value Value to get the class name for.
     * @return string Class name to use.
     */
    private function getClassName($value)
    {
        return ucfirst($value);
    }

    /**
     * Generate an entity class.
     *
     * @param string      $entity      Entity name to generate the class for.
     * @param FileManager $fileManager FileManager instance to use.
     * @param string      $type        Optional. Type of class construct. Can be 'class', interface' or 'trait'.
     */
    private function generateEntityClass($entity, FileManager $fileManager, $type = 'class')
    {
        /** @var PhpNamespace $namespace */
        list($file, $namespace) = $fileManager->createNewNamespacedFile('Spec');
        switch ($type) {
            case 'interface':
                $class = ClassType::from(self::GENERATOR_NAMESPACE . "\\Template\\{$entity}");
                $class->setInterface();
                foreach ($class->getMethods() as $method) {
                    $method->setPublic();
                }
                break;
            case 'trait':
                $class = ClassType::withBodiesFrom(self::GENERATOR_NAMESPACE . "\\Template\\{$entity}");
                $class->setTrait();
                break;
            default:
                $class = ClassType::withBodiesFrom(self::GENERATOR_NAMESPACE . "\\Template\\{$entity}");
        }
        $namespace->add($class);
        $fileManager->saveFile($file, "Spec/{$entity}.php");
    }

    /**
     * Generate a Section class.
     *
     * @param string      $section     Key of the section to generate.
     * @param mixed       $sectionSpec Spec data of the section to be generated.
     * @param FileManager $fileManager FileManager instance to use.
     * @param array       $extensionsMeta Extensions meta.
     * @return string Section class name.
     */
    private function generateSectionClass($section, $sectionSpec, FileManager $fileManager, $extensionsMeta)
    {
        list($file, $namespace) = $fileManager->createNewNamespacedFile('Spec\\Section');
        $className = $this->getClassName($section);
        $class     = $namespace->addClass($className)
                               ->setFinal();

        $sectionProcessorClass = self::GENERATOR_NAMESPACE . "\\Section\\{$className}";

        if (class_exists($sectionProcessorClass)) {
            if (Section\Tags::class === $sectionProcessorClass) {
                $sectionProcessor = new Section\Tags($extensionsMeta);
            } else {
                /** @var Section $sectionProcessor */
                $sectionProcessor = new $sectionProcessorClass();
            }

            $sectionProcessor->process($fileManager, $sectionSpec, $namespace, $class);
        }

        $fileManager->saveFile($file, "Spec/Section/{$className}.php");

        return $className;
    }

    /**
     * Generate a SpecRule interface.
     *
     * @param array       $specRuleKeys Array of spec rule keys to create constants for.
     * @param FileManager $fileManager  FileManager instance to use.
     */
    private function generateSpecRuleInterface($specRuleKeys, FileManager $fileManager)
    {
        list($file, $namespace) = $fileManager->createNewNamespacedFile('Spec');
        $interface = $namespace->addInterface('SpecRule');

        foreach ($specRuleKeys as $specRuleKey) {
            $interface->addConstant($this->getConstantName($specRuleKey), $specRuleKey);
        }

        $fileManager->saveFile($file, 'Spec/SpecRule.php');
    }

    /**
     * Generate the ErrorCode interface.
     *
     * @param array       $jsonSpec    JSON spec that contains the spec details.
     * @param FileManager $fileManager FileManager instance to use.
     */
    private function generateErrorCodeInterface($jsonSpec, FileManager $fileManager)
    {
        list($file, $namespace) = $fileManager->createNewNamespacedFile();
        $interface = $namespace->addInterface('ErrorCode');

        $errorCodes = array_unique(array_keys($jsonSpec['errors']));

        sort($errorCodes);

        foreach ($errorCodes as $errorCode) {
            $interface->addConstant($this->getConstantName($errorCode), $errorCode);
        }

        $fileManager->saveFile($file, 'ErrorCode.php');
    }

    /**
     * Adapt JSON spec data.
     *
     * @param array $jsonSpec JSON spec data to adapt.
     * @return array Adapted JSON spec data.
     */
    private function adaptJsonSpec($jsonSpec)
    {
        $jsonSpec['cssRulesets'] = $jsonSpec['css'];
        unset($jsonSpec['css']);

        $jsonSpec['docRulesets'] = $jsonSpec['doc'];
        unset($jsonSpec['doc']);

        $jsonSpec['attributeLists'] = $jsonSpec['attrLists'];
        unset($jsonSpec['attrLists']);

        $jsonSpec['declarationLists'] = $jsonSpec['declarationList'];
        unset($jsonSpec['declarationList']);

        $jsonSpec['descendantTagLists'] = $jsonSpec['descendantTagList'];
        unset($jsonSpec['descendantTagList']);

        $errorArray = [
            'format'      => $jsonSpec['errorFormats'],
            'specificity' => $jsonSpec['errorSpecificity'],
        ];

        $jsonSpec['errors'] = [];

        foreach ($errorArray as $key => $errors) {
            foreach ($errors as $error) {
                $jsonSpec['errors'][$error['code']][$key] = $error[$key];
            }
        }

        unset($jsonSpec['errorFormats'], $jsonSpec['errorSpecificity']);

        return $jsonSpec;
    }

    /**
     * Gather extensions meta.
     *
     * @param array $bundlesConfig Bundles config.
     * @return array Extensions meta.
     */
    private function gatherExtensionsMeta($bundlesConfig)
    {
        $extensions = [];
        foreach ($bundlesConfig as $bundleConfig) {
            if (! isset($bundleConfig['name']) || ! is_string($bundleConfig['name'])) {
                throw new Exception('Missing name in bundles.config.extensions.json');
            }
            if (! isset($bundleConfig['latestVersion']) || ! is_string($bundleConfig['latestVersion'])) {
                throw new Exception('Missing string latestVersion in bundles.config.extensions.json');
            }
            if (
                ! isset($bundleConfig['version'])
                ||
                ! (is_string($bundleConfig['version']) || is_array($bundleConfig['version']) )
            ) {
                throw new Exception('Missing string/array version in bundles.config.extensions.json');
            }

            if (!isset($extensions[$bundleConfig['name']])) {
                $extensions[$bundleConfig['name']] = [
                    'versions' => [],
                    'latestVersion' => '',
                ];
            }

            $versions = (array) $bundleConfig['version'];
            foreach ($versions as $version) {
                if (array_key_exists($version, $extensions[$bundleConfig['name']]['versions'])) {
                    throw new Exception("Version {$version} already seen for extension {$bundleConfig['name']}.");
                }
                $extensions[$bundleConfig['name']]['versions'][$version] = [
                    'hasCss'   => ! empty($bundleConfig['options']['hasCss']),
                    'hasBento' => (
                        isset($bundleConfig['options']['wrapper']) && 'bento' === $bundleConfig['options']['wrapper']
                    ),
                ];

                if (
                    version_compare(
                        $bundleConfig['latestVersion'],
                        $extensions[$bundleConfig['name']]['latestVersion'],
                        '>'
                    )
                ) {
                    $extensions[$bundleConfig['name']]['latestVersion'] = $bundleConfig['latestVersion'];
                }
            }
        }
        return $extensions;
    }

    /**
     * Collect all spec rule keys.
     *
     * @param array $jsonSpec JSON spec data.
     * @return array Array of spec rule keys.
     */
    private function collectSpecRuleKeys($jsonSpec)
    {
        $specRuleKeys = [];
        foreach ($jsonSpec as $sectionKey => $sectionData) {
            switch ($sectionKey) {
                case 'attributeLists':
                    $attributeLists = array_column($sectionData, 'attrs');
                    foreach ($attributeLists as $attributeList) {
                        foreach ($attributeList as $attributeEntry) {
                            foreach (array_keys($attributeEntry) as $specRuleKey) {
                                $specRuleKeys[$specRuleKey] = $specRuleKey;

                                $this->collectSpecRuleKeysFromSubset($specRuleKeys, $attributeEntry);
                            }
                        }
                    }
                    break;
                case 'cssRulesets':
                case 'docRulesets':
                case 'errors':
                    foreach ($sectionData as $ruleset) {
                        foreach (array_keys($ruleset) as $specRuleKey) {
                            $specRuleKeys[$specRuleKey] = $specRuleKey;
                        }
                    }
                    break;
                case 'declarationLists':
                    $declarationLists = array_column($sectionData, 'declaration');
                    foreach ($declarationLists as $declarationList) {
                        foreach ($declarationList as $declarationEntry) {
                            foreach (array_keys($declarationEntry) as $specRuleKey) {
                                $specRuleKeys[$specRuleKey] = $specRuleKey;
                            }
                        }
                    }
                    break;
                case 'tags':
                    foreach ($sectionData as $ruleset) {
                        foreach (array_keys($ruleset) as $specRuleKey) {
                            $specRuleKeys[$specRuleKey] = $specRuleKey;
                        }

                        $this->collectSpecRuleKeysFromSubset($specRuleKeys, $ruleset);
                    }
                    break;
                default:
            }
        }

        ksort($specRuleKeys);

        return $specRuleKeys;
    }

    /**
     * Collect all spec rule keys from a spec subset.
     *
     * @param array<string> $specRuleKeys Array of collected spec rule keys.
     * @param array         $subset       Subset to collect additional keys from.
     */
    private function collectSpecRuleKeysFromSubset(&$specRuleKeys, $subset)
    {
        if (!is_array($subset)) {
            return;
        }

        foreach ($subset as $key => $value) {
            if (is_string($key)) {
                $specRuleKeys[$key] = $key;
            }
            if (is_array($value)) {
                $this->collectSpecRuleKeysFromSubset($specRuleKeys, $value);
            }
        }
    }
}
