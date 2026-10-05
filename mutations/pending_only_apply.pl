# Let any inactive enrolment count as a pending application, not only an enrol_apply one: a
# student whose manual enrolment was suspended then keeps discovering an unlisted course.
s/if \(\$row->enrol === 'apply' && \(/if (true && (/;
