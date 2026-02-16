<?php

declare(strict_types=1);

namespace Rollpix\ConfigurableGallery\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;

/**
 * Renders module info (name, version, repo URL) in the system config page.
 */
class ModuleInfo extends Field
{
    private const MODULE_NAME = 'Rollpix_ConfigurableGallery';
    private const REPO_URL = 'https://github.com/ROLLPIX/M2-ConfigurableGallery';

    public function __construct(
        Context $context,
        private readonly ComponentRegistrarInterface $componentRegistrar,
        private readonly FileDriver $fileDriver,
        private readonly JsonSerializer $jsonSerializer,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $version = $this->getModuleVersion();

        $html = '<div style="padding:10px 0;">';
        $html .= '<table style="border-collapse:collapse;width:100%;max-width:500px;">';

        $html .= $this->renderRow('Módulo', '<strong>ROLLPIX — Configurable Gallery</strong>');
        $html .= $this->renderRow('Nombre técnico', '<code>' . self::MODULE_NAME . '</code>');
        $html .= $this->renderRow('Versión instalada', '<strong style="font-size:14px;">' . $version . '</strong>');
        $html .= $this->renderRow(
            'Repositorio',
            '<a href="' . self::REPO_URL . '" target="_blank" style="color:#006bb4;">'
            . self::REPO_URL . '</a>'
        );

        $html .= '</table>';
        $html .= '</div>';

        return $html;
    }

    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }

    private function renderRow(string $label, string $value): string
    {
        return '<tr>'
            . '<td style="padding:4px 15px 4px 0;color:#666;white-space:nowrap;vertical-align:top;">'
            . $label . '</td>'
            . '<td style="padding:4px 0;vertical-align:top;">' . $value . '</td>'
            . '</tr>';
    }

    private function getModuleVersion(): string
    {
        try {
            $path = $this->componentRegistrar->getPath(
                ComponentRegistrar::MODULE,
                self::MODULE_NAME
            );

            if ($path === null) {
                return 'N/A';
            }

            $composerFile = $path . '/composer.json';

            if (!$this->fileDriver->isExists($composerFile)) {
                return 'N/A';
            }

            $content = $this->fileDriver->fileGetContents($composerFile);
            $data = $this->jsonSerializer->unserialize($content);

            return $data['version'] ?? 'dev';
        } catch (\Exception $e) {
            return 'N/A';
        }
    }
}
