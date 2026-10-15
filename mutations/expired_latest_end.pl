# Report the earlier of two ended rows: the learner is told their access ended before it did.
s/\$state\['endsat'\] > \$best\['endsat'\]/\$state['endsat'] < \$best['endsat']/;
