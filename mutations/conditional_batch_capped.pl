# Leave capped enrol_conditional instances out of the batch's grouped count.
s/'conditional' => \(int\) \$instance->customint3,/'conditional' => 0,/;
