<?php

namespace Fhp\Action;

use Fhp\BaseAction;
use Fhp\Model\SEPAAccount;
use Fhp\Model\StatementOfAccount\StatementOfAccount;
use Fhp\MT940\Dialect\PostbankMT940;
use Fhp\MT940\Dialect\SpardaMT940;
use Fhp\MT940\MT940;
use Fhp\MT940\MT940Exception;
use Fhp\Protocol\UnexpectedResponseException;
use Fhp\Segment\Common\Kti;
use Fhp\Segment\Common\Kto;
use Fhp\Segment\Common\KtvV3;
use Fhp\Segment\HIRMS\Rueckmeldungscode;
use Fhp\Segment\KAZ\HIKAZ;
use Fhp\Segment\KAZ\HIKAZS;
use Fhp\Segment\KAZ\HKKAZv4;
use Fhp\Segment\KAZ\HKKAZv5;
use Fhp\Segment\KAZ\HKKAZv6;
use Fhp\Segment\KAZ\HKKAZv7;
use Fhp\UnsupportedException;

/**
 * Retrieves statements for one specific account or for all accounts that the user has access to. A statement is a
 * series of financial transactions that pertain to the account, grouped by day.
 */
class GetStatementOfAccount extends BaseAction
{
    // Request (not available after serialization, i.e. not available in processResponse()).
    /** @var SEPAAccount */
    private $account;
    /** @var \DateTime */
    private $from;
    /** @var \DateTime */
    private $to;
    /** @var bool */
    private $allAccounts;

    // Information from the BPD needed to interpret the response.
    /** @var string */
    private $bankName;

    // Response
    /** @var StatementOfAccount */
    private $statement;

    /**
     * @param SEPAAccount $account The account to get the statement for. This can be constructed based on information
     *     that the user entered, or it can be {@link SEPAAccount} instance retrieved from {@link #getAccounts()}.
     * @param \DateTime|null $from If set, only transactions after this date (inclusive) are returned.
     * @param \DateTime|null $to If set, only transactions before this date (inclusive) are returned.
     * @param bool $allAccounts If set to true, will return statements for all accounts of the user. You still need to
     *     pass one of the accounts into $account, though.
     * @return GetStatementOfAccount A new action instance.
     */
    public static function create(SEPAAccount $account, $from = null, $to = null, $allAccounts = false)
    {
        if (isset($from) && isset($to) && $from > $to) {
            throw new \InvalidArgumentException('From-date must be before to-date');
        }

        $result = new GetStatementOfAccount();
        $result->account = $account;
        $result->from = $from;
        $result->to = $to;
        $result->allAccounts = $allAccounts;
        return $result;
    }

    public function serialize()
    {
        return serialize([parent::serialize(), $this->bankName]);
    }

    public function unserialize($serialized)
    {
        list($parentSerialized, $this->bankName) = unserialize($serialized);
        parent::unserialize($parentSerialized);
    }

    /**
     * @return StatementOfAccount
     * @throws \Exception See {@link #ensureSuccess()}.
     */
    public function getStatement()
    {
        $this->ensureSuccess();
        return $this->statement;
    }

    /** {@inheritdoc} */
    public function createRequest($bpd, $upd)
    {
        $this->bankName = $bpd->getBankName();

        /** @var HIKAZS $hikazs */
        $hikazs = $bpd->requireLatestSupportedParameters('HIKAZS');
        if ($this->allAccounts && !$hikazs->getParameter()->getAlleKontenErlaubt()) {
            throw new \InvalidArgumentException('The bank do not permit the use of allAccounts=true');
        }
        switch ($hikazs->getVersion()) {
            case 4:
                return HKKAZv4::create(Kto::fromAccount($this->account), $this->from, $this->to);
            case 5:
                return HKKAZv5::create(KtvV3::fromAccount($this->account), $this->allAccounts, $this->from, $this->to);
            case 6:
                return HKKAZv6::create(KtvV3::fromAccount($this->account), $this->allAccounts, $this->from, $this->to);
            case 7:
                return HKKAZv7::create(Kti::fromAccount($this->account), $this->allAccounts, $this->from, $this->to);
            default:
                throw new UnsupportedException('Unsupported HKKAZ version: ' . $hikazs->getVersion());
        }
    }

    /** {@inheritdoc} */
    public function processResponse($response)
    {
        parent::processResponse($response);

        // Banks send just 3010 and no HIKAZ in case there are no transactions.
        $isUnavailable = $response->findRueckmeldung(Rueckmeldungscode::NICHT_VERFUEGBAR) !== null;
        $responseHikaz = $response->findSegments(HIKAZ::class);
        $numResponseSegments = count($responseHikaz);
        if (!$isUnavailable && $numResponseSegments < count($this->getRequestSegmentNumbers())) {
            throw new UnexpectedResponseException("Only got $numResponseSegments HIKAZ response segments!");
        }

        if (strpos(strtolower($this->bankName), 'sparda') !== false) {
            $parser = new SpardaMT940();
        } elseif (strpos(strtolower($this->bankName), 'postbank') !== false) {
            $parser = new PostbankMT940();
        } else {
            $parser = new MT940();
        }

        try {
            $this->statement = new StatementOfAccount();
            /** @var HIKAZ $hikaz */
            foreach ($responseHikaz as $hikaz) {
                $parsedStatement = $parser->parse($hikaz->getGebuchteUmsaetze()->getData());
                \Fhp\Response\GetStatementOfAccount::addFromArray($parsedStatement, $this->statement);
            }
        } catch (MT940Exception $e) {
            throw new \InvalidArgumentException('Invalid MT940 data', 0, $e);
        }
        // TODO Implement pagination somewhere, not necessarily here.
    }
}