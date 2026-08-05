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
}
