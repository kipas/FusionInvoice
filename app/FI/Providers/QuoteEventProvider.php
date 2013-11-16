<?php namespace FI\Providers;

use Illuminate\Support\ServiceProvider;
use FI\Calculators\QuoteCalculator;

class QuoteEventProvider extends ServiceProvider {

	public function register() {}

	public function boot()
	{
		// Create the empty quote amount record
		\Event::listen('quote.created', function($quoteId, $invoiceGroupId)
		{
			\Log::info('Event Handler: quote.created');

			$quoteAmount  = \App::make('FI\Storage\Interfaces\QuoteAmountRepositoryInterface');
			$invoiceGroup = \App::make('FI\Storage\Interfaces\InvoiceGroupRepositoryInterface');

			$quoteAmount->create(array(
				'quote_id'       => $quoteId,
				'item_subtotal'  => 0,
				'item_tax_total' => 0,
				'tax_total'      => 0,
				'total'          => 0
				)
			);

			$invoiceGroup->incrementNextId($invoiceGroupId);
		});

		// Create the quote item amount record
		\Event::listen('quote.item.created', function($itemId)
		{
			\Log::info('Event Handler: quote.item.created');

			$quoteItem       = \App::make('FI\Storage\Interfaces\QuoteItemRepositoryInterface');
            $quoteItemAmount = \App::make('FI\Storage\Interfaces\QuoteItemAmountRepositoryInterface');
            $taxRate         = \App::make('FI\Storage\Interfaces\TaxRateRepositoryInterface');

            $quoteItem = $quoteItem->find($itemId);

            if ($quoteItem->tax_rate_id)
            {
                    $taxRatePercent = $taxRate->find($quoteItem->tax_rate_id)->percent;
            }
            else
            {
                    $taxRatePercent = 0;
            }

            $subtotal = $quoteItem->quantity * $quoteItem->price;
            $taxTotal = $subtotal * ($taxRatePercent / 100);
            $total    = $subtotal + $taxTotal;

            $quoteItemAmount->create(array(
                    'item_id'   => $quoteItem->id,
                    'subtotal'  => $subtotal,
                    'tax_total' => $taxTotal,
                    'total'     => $total
                )
            );
		});

		// Calculate all quote amounts
		\Event::listen('quote.modified', function($quoteId)
		{
			\Log::info('Event Handler: quote.modified');

			// Resolve ALL THE THINGS
			$quoteItem       = \App::make('FI\Storage\Interfaces\QuoteItemRepositoryInterface');
			$quoteItemAmount = \App::make('FI\Storage\Interfaces\QuoteItemAmountRepositoryInterface');
			$quoteAmount     = \App::make('FI\Storage\Interfaces\QuoteAmountRepositoryInterface');
			$quoteTaxRate    = \App::make('FI\Storage\Interfaces\QuoteTaxRateRepositoryInterface');
			$taxRate         = \App::make('FI\Storage\Interfaces\TaxRateRepositoryInterface');

			// Retrieve the required records
			$items         = $quoteItem->findByQuoteId($quoteId);
			$quoteTaxRates = $quoteTaxRate->findByQuoteId($quoteId);

			// Set up the calculator
			$calculator = new QuoteCalculator;
			$calculator->setId($quoteId);

			// Add the items to be calculated
			foreach ($items as $item)
			{
				if ($item->tax_rate_id)
				{
					$taxRatePercent = $taxRate->find($item->tax_rate_id)->percent;
				}
				else
				{
					$taxRatePercent = 0;
				}

				$calculator->addItem($item->id, $item->quantity, $item->price, $taxRatePercent);
			}

			// Add the quote tax rates to be calculated
			foreach ($quoteTaxRates as $quoteTax)
			{
				$taxRatePercent = $taxRate->find($quoteTax->tax_rate_id)->percent;

				$calculator->addTaxRate($quoteTax->tax_rate_id, $taxRatePercent, $quoteTax->include_item_tax);
			}

			// Run the calculations
			$calculator->calculate();

			// Get the calculated values
			$calculatedItemAmounts   = $calculator->getCalculatedItemAmounts();
			$calculatedQuoteTaxRates = $calculator->getCalculatedTaxRates();
			$calculatedQuoteAmount   = $calculator->getCalculatedAmount();

			// Update the item amount records
			foreach ($calculatedItemAmounts as $calculatedItemAmount)
			{
				$quoteItemAmount->updateByItemId($calculatedItemAmount, $calculatedItemAmount['item_id']);
			}

			// Update the quote tax rate records
			foreach ($calculatedQuoteTaxRates as $calculatedQuoteTaxRate)
			{
				$quoteTaxRate->updateByQuoteIdAndTaxRateId($calculatedQuoteTaxRate, $quoteId, $calculatedQuoteTaxRate['tax_rate_id']);
			}

			// Update the quote amount record
			$quoteAmount->updateByQuoteId($calculatedQuoteAmount, $quoteId);
		});
	}
}