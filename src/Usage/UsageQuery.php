<?php

namespace Systemverk\LaravelApiUsage\Usage;

/**
 * "How much was the API used in this period, by this actor?"
 *
 *     ApiUsage::usage()
 *         ->thisMonth()
 *         ->forActor(UsageActor::organization(42))
 *         ->summary();
 *
 * Period selection, filtering, summary() and count() all live on
 * {@see PeriodQuery}; this is simply the plain entry point that adds nothing
 * on top of them.
 */
class UsageQuery extends PeriodQuery {}
