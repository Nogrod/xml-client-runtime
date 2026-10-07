<?php

namespace Nogrod\XMLClientRuntime;

/**
 * Sabre writer with the hooks the generated types need.
 */
class Writer extends \Sabre\Xml\Writer
{
    /**
     * Whether this writer builds the document in memory instead of writing it to a file.
     *
     * Set by Client::openSabreWriter(), read by Client::closeSabreWriter().
     */
    public bool $inMemory = false;

    /**
     * The default namespace (xmlns) in scope for the element currently open.
     */
    private ?string $defaultNamespace = null;

    /**
     * The default namespace in scope outside of each open element, innermost last.
     *
     * @var array<int, string|null>
     */
    private array $defaultNamespaceStack = [];

    /**
     * Whether the element currently open declares its default namespace itself.
     */
    private bool $namespaceDeclaredHere = false;

    /**
     * Suppresses the xmlns declarations sabre writes automatically.
     *
     * On the first element sabre emits every entry of the namespaceMap as an xmlns
     * attribute. The generated types write their own xmlns in xmlSerialize(), so
     * without this the declaration would end up in the document twice.
     */
    public function markNamespacesWritten(): void
    {
        $this->namespacesWritten = true;
    }

    /**
     * Declares $namespace as default namespace on the open element, unless it already is.
     *
     * The generated types call this for every element they write. Declaring it only
     * where it changes keeps it on the root of a document instead of repeating it on
     * every element below.
     */
    public function writeDefaultNamespace(string $namespace): void
    {
        if ($this->defaultNamespace !== $namespace) {
            $this->writeAttribute('xmlns', $namespace);
        }
    }

    /**
     * Declares $namespace as default namespace on the open element even if it is already
     * in scope, unless this element declares it already.
     *
     * Used for global elements: eBay processes the requests of a BulkDataExchangeRequests
     * file one by one and rejects those without their own xmlns.
     */
    public function writeRootNamespace(string $namespace): void
    {
        if (!$this->namespaceDeclaredHere || $this->defaultNamespace !== $namespace) {
            $this->writeAttribute('xmlns', $namespace);
        }
    }

    public function startElement($name): bool
    {
        // A Clark-notation name is opened through startElementNs(), or through this
        // method again with the local name, and is tracked there.
        if ('{' !== $name[0]) {
            $this->defaultNamespaceStack[] = $this->defaultNamespace;
            $this->namespaceDeclaredHere = false;
        }

        return parent::startElement($name);
    }

    public function startElementNs(?string $prefix, string $name, ?string $namespace): bool
    {
        $this->defaultNamespaceStack[] = $this->defaultNamespace;
        $this->namespaceDeclaredHere = null === $prefix && null !== $namespace;
        if ($this->namespaceDeclaredHere) {
            $this->defaultNamespace = $namespace;
        }

        return parent::startElementNs($prefix, $name, $namespace);
    }

    public function endElement(): bool
    {
        $this->defaultNamespace = array_pop($this->defaultNamespaceStack);

        return parent::endElement();
    }

    public function fullEndElement(): bool
    {
        $this->defaultNamespace = array_pop($this->defaultNamespaceStack);

        return parent::fullEndElement();
    }

    public function writeAttribute($name, $value): bool
    {
        if ('xmlns' === $name) {
            $this->defaultNamespace = $value;
            $this->namespaceDeclaredHere = true;
        }

        return parent::writeAttribute($name, $value);
    }
}
