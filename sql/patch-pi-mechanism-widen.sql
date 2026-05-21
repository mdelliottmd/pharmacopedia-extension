-- pi_mechanism was VARCHAR(255) per the original Phase-1 spec. Real-world
-- mechanism strings (CPIC rec text + FDA labeling sections + CPIC pair-
-- level [PMID a, b, c] enrichment) routinely exceed that, truncating
-- mid-PMID and creating fake citations (the "23422" ghost-PMID was the
-- front half of a real PMID chopped off at byte 255). Widening to
-- VARCHAR(2048) gives 8x headroom without going TEXT.

ALTER TABLE /*_*/pcp_interactions
    MODIFY COLUMN pi_mechanism VARCHAR(2048) DEFAULT NULL;
