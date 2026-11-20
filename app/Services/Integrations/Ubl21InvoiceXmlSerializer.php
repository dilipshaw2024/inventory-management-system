<?php

namespace App\Services\Integrations;

use DOMDocument;
use DOMElement;

/** Serialize the provider-neutral UBL model to a canonical XML invoice. */
class Ubl21InvoiceXmlSerializer
{
    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    private const INV = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    public function serialize(array $document): string
    {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $root = $xml->createElementNS(self::INV, 'Invoice');
        $root->setAttribute('xmlns:cac', self::CAC);
        $root->setAttribute('xmlns:cbc', self::CBC);
        $xml->appendChild($root);

        $this->text($xml, $root, 'cbc:UBLVersionID', '2.1');
        $this->text($xml, $root, 'cbc:CustomizationID', $document['customization_id'] ?? null);
        $this->text($xml, $root, 'cbc:ProfileID', $document['profile_id'] ?? null);
        $invoice = $document['invoice'] ?? [];
        $this->text($xml, $root, 'cbc:ID', $invoice['id'] ?? null);
        $this->text($xml, $root, 'cbc:IssueDate', $invoice['issue_date'] ?? null);
        $this->text($xml, $root, 'cbc:DueDate', $invoice['due_date'] ?? null);
        $this->text($xml, $root, 'cbc:DocumentCurrencyCode', $invoice['document_currency_code'] ?? null);

        $this->party($xml, $root, 'cac:AccountingSupplierParty', $document['supplier_party'] ?? null);
        $this->party($xml, $root, 'cac:AccountingCustomerParty', $document['customer_party'] ?? null);

        $taxTotal = $this->element($xml, $root, 'cac:TaxTotal');
        $this->amount($xml, $taxTotal, 'cbc:TaxAmount', $invoice['tax_amount'] ?? 0, $invoice['tax_currency_code'] ?? 'INR');
        $taxSubtotal = $this->element($xml, $taxTotal, 'cac:TaxSubtotal');
        $this->amount($xml, $taxSubtotal, 'cbc:TaxableAmount', $invoice['tax_exclusive_amount'] ?? 0, $invoice['tax_currency_code'] ?? 'INR');
        $this->amount($xml, $taxSubtotal, 'cbc:TaxAmount', $invoice['tax_amount'] ?? 0, $invoice['tax_currency_code'] ?? 'INR');
        $taxCategory = $this->element($xml, $taxSubtotal, 'cac:TaxCategory');
        $this->text($xml, $taxCategory, 'cbc:ID', ((float) ($invoice['tax_amount'] ?? 0)) > 0 ? 'S' : 'E');

        $totals = $this->element($xml, $root, 'cac:LegalMonetaryTotal');
        $currency = $invoice['document_currency_code'] ?? 'INR';
        $this->amount($xml, $totals, 'cbc:TaxExclusiveAmount', $invoice['tax_exclusive_amount'] ?? 0, $currency);
        $this->amount($xml, $totals, 'cbc:TaxInclusiveAmount', $invoice['tax_inclusive_amount'] ?? 0, $currency);
        $this->amount($xml, $totals, 'cbc:PayableAmount', $invoice['payable_amount'] ?? 0, $currency);

        foreach ((array) ($document['lines'] ?? []) as $line) {
            $lineNode = $this->element($xml, $root, 'cac:InvoiceLine');
            $this->text($xml, $lineNode, 'cbc:ID', $line['id'] ?? null);
            $quantity = $this->text($xml, $lineNode, 'cbc:InvoicedQuantity', $line['invoiced_quantity'] ?? 0);
            $quantity?->setAttribute('unitCode', (string) ($line['unit_code'] ?? 'EA'));
            $this->amount($xml, $lineNode, 'cbc:LineExtensionAmount', $line['line_extension_amount'] ?? 0, $currency);
            $item = $this->element($xml, $lineNode, 'cac:Item');
            $itemData = $line['item'] ?? [];
            $this->text($xml, $item, 'cbc:Description', $itemData['description'] ?? null);
            $this->text($xml, $item, 'cbc:Name', $itemData['name'] ?? null);
            $category = $this->element($xml, $item, 'cac:ClassifiedTaxCategory');
            $this->text($xml, $category, 'cbc:ID', $itemData['classified_tax_category'] ?? 'E');
            $this->text($xml, $category, 'cbc:Percent', number_format((float) ($itemData['percent'] ?? 0), 2, '.', ''));
            if (!empty($itemData['sellers_item_identification'])) {
                $identification = $this->element($xml, $item, 'cac:SellersItemIdentification');
                $this->text($xml, $identification, 'cbc:ID', $itemData['sellers_item_identification']);
            }
            $price = $this->element($xml, $lineNode, 'cac:Price');
            $this->amount($xml, $price, 'cbc:PriceAmount', $line['price_amount'] ?? 0, $currency);
        }

        return $xml->saveXML();
    }

    private function party(DOMDocument $xml, DOMElement $root, string $wrapper, ?array $party): void
    {
        if (!$party) return;
        $wrapperNode = $this->element($xml, $root, $wrapper);
        $partyNode = $this->element($xml, $wrapperNode, 'cac:Party');
        if (!empty($party['endpoint_id'])) $this->text($xml, $partyNode, 'cbc:EndpointID', $party['endpoint_id']);
        $name = $this->element($xml, $partyNode, 'cac:PartyName');
        $this->text($xml, $name, 'cbc:Name', $party['name'] ?? null);
        if (!empty($party['address'])) {
            $address = $this->element($xml, $partyNode, 'cac:PostalAddress');
            $line = $this->element($xml, $address, 'cac:AddressLine');
            $this->text($xml, $line, 'cbc:Line', $party['address']);
        }
        if (!empty($party['tax_registration_number'])) {
            $tax = $this->element($xml, $partyNode, 'cac:PartyTaxScheme');
            $this->text($xml, $tax, 'cbc:CompanyID', $party['tax_registration_number']);
        }
    }

    private function amount(DOMDocument $xml, DOMElement $parent, string $name, $value, string $currency): DOMElement
    {
        $node = $this->text($xml, $parent, $name, number_format((float) $value, 2, '.', ''));
        $node->setAttribute('currencyID', $currency);
        return $node;
    }

    private function element(DOMDocument $xml, DOMElement $parent, string $name): DOMElement
    {
        [$prefix] = explode(':', $name, 2);
        $namespace = $prefix === 'cac' ? self::CAC : self::CBC;
        $node = $xml->createElementNS($namespace, $name);
        $parent->appendChild($node);
        return $node;
    }

    private function text(DOMDocument $xml, DOMElement $parent, string $name, $value): ?DOMElement
    {
        if ($value === null || $value === '') return null;
        $node = $this->element($xml, $parent, $name);
        $node->appendChild($xml->createTextNode((string) $value));
        return $node;
    }
}
